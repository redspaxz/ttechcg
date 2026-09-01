<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
use App\Shared\Security\LoginMethodSettings;
use App\Shared\Security\LoginMethodSettingsService;
use App\Shared\Security\LocalMfaService;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\RecordsUserService;
use App\Shared\Security\SecurityLogger;
use App\Shared\Spreadsheet\XlsxWriter;
use App\Shared\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PickupsheetController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PickupSheetService $service,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Captcha $captcha,
        private readonly array $config,
        private readonly string $storageMode,
        private readonly bool $pickupOperational,
        private readonly RecordsAccess $recordsAccess,
        private readonly RecordsSession $recordsSession,
        private readonly RecordsUserService $recordsUserService,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogger $securityLogger,
        private readonly ?LoginMethodSettingsService $loginMethodSettings = null,
        private readonly ?LocalMfaService $localMfa = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'create');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $flash = $_SESSION['_pickup_flash'] ?? null;
        $errors = $_SESSION['_pickup_errors'] ?? [];
        $old = $_SESSION['_pickup_old'] ?? [];
        unset($_SESSION['_pickup_flash'], $_SESSION['_pickup_errors'], $_SESSION['_pickup_old']);

        $consignorSuggestions = [];
        if ($this->pickupOperational) {
            try {
                $consignorSuggestions = $this->service->consignorSuggestions();
            } catch (Throwable $exception) {
                error_log('Pickup consignor suggestions could not be loaded: ' . $exception->getMessage());
            }
        }

        $body = $this->view->render('pickupsheet/show', [
            'pageTitle' => 'Cash shipment pickup sheet',
            'pageDescription' => 'Securely record cash shipment collections and pickup-sheet totals.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'csrfToken' => $this->csrf->token(),
            'captcha' => $this->captcha->issue(),
            'flash' => $flash,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'pickupOperational' => $this->pickupOperational,
            'recordsRole' => $authorization->role,
            'recordsUsername' => $authorization->username,
            'recordsFullName' => $authorization->fullName(),
            'recordsIdentityProvider' => $authorization->identityProvider,
            'canCrmView' => $authorization->can('crm_view'),
            'consignorSuggestions' => $consignorSuggestions,
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function searchConsignors(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'create');
        if ($authorization instanceof Response) {
            return $authorization;
        }
        $rateLimitResponse = $this->rateLimit($request, 'pickup-consignor-search', 180, 300);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        try {
            return Response::json([
                'suggestions' => $this->service->consignorSuggestions($request->queryString('q'), 12),
            ], 200, $this->privateHeaders());
        } catch (InvalidArgumentException $exception) {
            return Response::json(['suggestions' => [], 'message' => $exception->getMessage()], 422, $this->privateHeaders());
        } catch (RuntimeException $exception) {
            error_log('Pickup consignor search failed: ' . $exception->getMessage());
            return Response::json(['suggestions' => []], 503, $this->privateHeaders());
        }
    }

    public function store(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'create');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $rateLimitResponse = $this->rateLimit($request, 'pickup-submit', 30, 3600);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419);
        }

        $input = [
            'agent_name' => $request->input('agent_name'),
            'collection_date' => $request->input('collection_date'),
            'shipments' => $this->shipmentsCheckedByPrincipal($request->arrayInput('shipments'), $authorization),
            'privacy_consent' => $request->input('privacy_consent'),
        ];

        if (!$this->pickupOperational) {
            $_SESSION['_pickup_errors'] = ['Pickup-sheet storage is temporarily unavailable. Please try again later.'];
            $_SESSION['_pickup_old'] = $input;
            return Response::redirect($request->basePath . '/dhl/pickupsheet/');
        }

        if ($request->input('website') !== '') {
            $this->securityLogger->event('pickupsheet.honeypot', $request, 'blocked');
            $_SESSION['_pickup_flash'] = 'Pickup sheet saved.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/');
        }

        if (!$this->captcha->validate($request->input('captcha_nonce'), $request->input('captcha_answer'))) {
            $this->securityLogger->event('pickupsheet.captcha', $request, 'denied');
            $_SESSION['_pickup_errors'] = ['Please complete the human verification with the correct answer.'];
            $_SESSION['_pickup_old'] = $input;
            return Response::redirect($request->basePath . '/dhl/pickupsheet/');
        }

        $lastSubmissionAt = (int) ($_SESSION['_last_pickup_sheet_at'] ?? 0);
        if ($lastSubmissionAt > 0 && time() - $lastSubmissionAt < 10) {
            $this->securityLogger->event('pickupsheet.session_cooldown', $request, 'denied');
            $_SESSION['_pickup_errors'] = ['Please wait a moment before saving another pickup sheet.'];
            $_SESSION['_pickup_old'] = $input;
            return Response::redirect($request->basePath . '/dhl/pickupsheet/');
        }

        try {
            $pickupSheet = $this->service->submit($input);
            $this->securityLogger->event('pickupsheet.submission', $request, 'accepted', [
                'resource_id' => substr(hash('sha256', $pickupSheet->referenceNumber), 0, 24),
                'shipment_count' => $pickupSheet->shipmentCount(),
            ]);
            $_SESSION['_pickup_flash'] = sprintf(
                'Pickup sheet %s saved with %d shipment%s and a total of %s XAF.',
                $pickupSheet->referenceNumber,
                $pickupSheet->shipmentCount(),
                $pickupSheet->shipmentCount() === 1 ? '' : 's',
                number_format($pickupSheet->totalCashReceivedXaf),
            );
            $_SESSION['_last_pickup_sheet_at'] = time();
        } catch (InvalidArgumentException $exception) {
            $this->securityLogger->event('pickupsheet.validation', $request, 'denied');
            $_SESSION['_pickup_errors'] = [$exception->getMessage()];
            $_SESSION['_pickup_old'] = $input;
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->securityLogger->event('pickupsheet.persistence', $request, 'failed');
            $_SESSION['_pickup_errors'] = ['We could not save the pickup sheet. Please try again later.'];
            $_SESSION['_pickup_old'] = $input;
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/');
    }

    public function dashboard(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'dashboard');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $summary = ['sheetCount' => 0, 'shipmentCount' => 0, 'totalCashXaf' => 0, 'latestCreatedAt' => null];
        $activity = [];
        $destinations = [];
        $senders = [];
        $userActivity = $this->emptyPagination() + ['activeRecords' => 0];
        $auditLogs = $this->emptyPagination();
        $recentSheets = $this->emptyPagination();
        $accounts = [];
        $errors = [];

        try {
            $summary = $this->service->summary();
            $activity = $this->completeActivity($this->service->activityByDay(14), 14);
            $destinations = $this->service->topDestinations(5);
            $senders = $this->service->topSenders(12, 10);
            $accounts = $this->recordsUserService->accounts($authorization);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors = ['Dashboard activity could not be loaded. Check the MySQL connection and account schema.'];
        }

        try {
            $userActivity = $this->recordsSession->paginatedActivitySummary(
                30,
                $this->pageNumber($request, 'login_page'),
                10,
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors[] = 'User login activity could not be loaded. Check the session-activity schema.';
        }

        try {
            $auditLogs = $this->securityLogger->paginatedPickupsheet(
                $this->pageNumber($request, 'log_page'),
                10,
            );
            $auditLogs['items'] = $this->auditLogsWithIdentity(
                $auditLogs['items'],
                $authorization,
                $accounts,
                $this->recordsSession->activitySummary(30, 100),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors[] = 'Detailed user logs could not be loaded. Check the security-event schema.';
        }

        try {
            $recentSheets = $this->service->paginated($this->pageNumber($request, 'recent_page'), 10);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors[] = 'Recent pickup sheets could not be loaded.';
        }

        $body = $this->view->render('pickupsheet/dashboard', [
            'pageTitle' => 'Pickupsheet control panel',
            'pageDescription' => 'Administrative KPIs, activity, and access controls for Pickupsheet.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'summary' => $summary,
            'activity' => $activity,
            'destinations' => $destinations,
            'senders' => $senders,
            'userActivity' => $userActivity,
            'auditLogs' => $auditLogs,
            'recentSheets' => $recentSheets,
            'accounts' => $accounts,
            'errors' => $errors,
            'recordsUsername' => $authorization->username,
            'recordsRole' => $authorization->role,
            'recordsFullName' => $authorization->fullName(),
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function dashboardUserActivityPage(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'dashboard', false);
        if ($authorization instanceof Response) {
            return $authorization;
        }

        try {
            return Response::html(
                $this->view->renderPartial('pickupsheet/_dashboard-user-activity', [
                    'basePath' => $request->basePath,
                    'userActivity' => $this->recordsSession->paginatedActivitySummary(30, $this->pageNumber($request, 'login_page'), 10),
                    'currentLogPage' => $this->pageNumber($request, 'log_page'),
                    'currentRecentPage' => $this->pageNumber($request, 'recent_page'),
                ]),
                200,
                $this->privateHeaders(),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('User activity could not be loaded.', 503, $this->privateHeaders());
        }
    }

    public function dashboardAuditLogPage(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'dashboard', false);
        if ($authorization instanceof Response) {
            return $authorization;
        }

        try {
            $accounts = $this->recordsUserService->accounts($authorization);
            $auditLogs = $this->securityLogger->paginatedPickupsheet($this->pageNumber($request, 'log_page'), 10);
            $auditLogs['items'] = $this->auditLogsWithIdentity(
                $auditLogs['items'],
                $authorization,
                $accounts,
                $this->recordsSession->activitySummary(30, 100),
            );
            return Response::html(
                $this->view->renderPartial('pickupsheet/_dashboard-audit-logs', [
                    'basePath' => $request->basePath,
                    'auditLogs' => $auditLogs,
                    'currentLoginPage' => $this->pageNumber($request, 'login_page'),
                    'currentRecentPage' => $this->pageNumber($request, 'recent_page'),
                ]),
                200,
                $this->privateHeaders(),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Detailed user logs could not be loaded.', 503, $this->privateHeaders());
        }
    }

    public function dashboardRecentSheetsPage(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'dashboard', false);
        if ($authorization instanceof Response) {
            return $authorization;
        }

        try {
            return Response::html(
                $this->view->renderPartial('pickupsheet/_dashboard-recent-sheets', [
                    'basePath' => $request->basePath,
                    'recentSheets' => $this->service->paginated($this->pageNumber($request, 'recent_page'), 10),
                    'currentLoginPage' => $this->pageNumber($request, 'login_page'),
                    'currentLogPage' => $this->pageNumber($request, 'log_page'),
                ]),
                200,
                $this->privateHeaders(),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Recent pickup sheets could not be loaded.', 503, $this->privateHeaders());
        }
    }

    public function submissions(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'list');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $records = $this->submissionRecords($request, $authorization);
        $flash = $_SESSION['_pickup_records_flash'] ?? null;
        unset($_SESSION['_pickup_records_flash']);

        $body = $this->view->render('pickupsheet/submissions', array_merge($records, [
            'pageTitle' => 'Submitted pickup sheets',
            'pageDescription' => 'Pickup-sheet records and shipment exports.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'flash' => is_string($flash) ? $flash : null,
        ]));

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function submissionsPage(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'paginate');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        return Response::html(
            $this->view->renderPartial('pickupsheet/_submission-records', $this->submissionRecords($request, $authorization)),
            200,
            $this->privateHeaders(),
        );
    }

    public function edit(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'edit');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $pickupSheet = $this->service->findByReference($request->queryString('reference'));
        if ($pickupSheet === null) {
            return Response::html('Pickup sheet not found.', 404, $this->privateHeaders());
        }

        $flash = $_SESSION['_pickup_edit_flash'] ?? null;
        $errors = $_SESSION['_pickup_edit_errors'] ?? [];
        $old = $_SESSION['_pickup_edit_old'] ?? [];
        unset($_SESSION['_pickup_edit_flash'], $_SESSION['_pickup_edit_errors'], $_SESSION['_pickup_edit_old']);

        $body = $this->view->render('pickupsheet/edit', [
            'pageTitle' => 'Edit ' . $pickupSheet->referenceNumber,
            'pageDescription' => 'Correct a generated pickup sheet with an audit trail.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'pickupSheet' => $pickupSheet,
            'flash' => is_string($flash) ? $flash : null,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'recordsUsername' => $authorization->username,
            'recordsRole' => $authorization->role,
            'recordsFullName' => $authorization->fullName(),
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function updatePickupSheet(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'edit');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $rateLimitResponse = $this->rateLimit($request, 'pickup-records-edit', 60, 3600);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.edit_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        $reference = $request->input('reference');
        $input = [
            'agent_name' => $request->input('agent_name'),
            'collection_date' => $request->input('collection_date'),
            'shipments' => $this->shipmentsCheckedByPrincipal($request->arrayInput('shipments'), $authorization),
        ];

        try {
            $updated = $this->service->update(
                $reference,
                $input,
                substr(hash('sha256', $authorization->username), 0, 24),
            );
            $_SESSION['_pickup_edit_flash'] = 'Pickup sheet updated. The before-and-after values were added to the audit log.';
            $this->securityLogger->event('pickupsheet.record_edit', $request, 'accepted', [
                'actor_id' => substr(hash('sha256', $authorization->username), 0, 24),
                'resource_id' => substr(hash('sha256', $updated->referenceNumber), 0, 24),
                'shipment_count' => $updated->shipmentCount(),
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_edit_errors'] = [$exception->getMessage()];
            $_SESSION['_pickup_edit_old'] = $input;
            $this->securityLogger->event('pickupsheet.record_edit', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_pickup_edit_errors'] = ['The pickup sheet could not be updated. Check MySQL and try again.'];
            $_SESSION['_pickup_edit_old'] = $input;
            $this->securityLogger->event('pickupsheet.record_edit', $request, 'failed');
        }

        return Response::redirect(
            $request->basePath . '/dhl/pickupsheet/submissions/edit?reference=' . rawurlencode($reference),
        );
    }

    public function markPickupSheetPaid(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'mark_paid');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $denied = $this->pickupLifecycleWriteGuard($request, 'mark-paid');
        if ($denied !== null) {
            return $denied;
        }

        $reference = $request->input('reference');
        try {
            $paid = $this->service->markPaid($reference, $this->actorId($authorization));
            $_SESSION['_pickup_records_flash'] = 'Pickup sheet ' . $paid->referenceNumber . ' marked paid.';
            $this->securityLogger->event('pickupsheet.record_paid', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'resource_id' => substr(hash('sha256', $paid->referenceNumber), 0, 24),
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_records_flash'] = $exception->getMessage();
            $this->securityLogger->event('pickupsheet.record_paid', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_pickup_records_flash'] = 'The pickup sheet could not be marked paid. Check MySQL and try again.';
            $this->securityLogger->event('pickupsheet.record_paid', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions');
    }

    public function deletePickupSheet(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'delete');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $denied = $this->pickupLifecycleWriteGuard($request, 'delete');
        if ($denied !== null) {
            return $denied;
        }

        $reference = $request->input('reference');
        try {
            $this->service->delete($reference, $this->actorId($authorization));
            $_SESSION['_pickup_records_flash'] = 'Pickup sheet ' . $reference . ' deleted from active records. Its audit history was retained.';
            $this->securityLogger->event('pickupsheet.record_delete', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'resource_id' => substr(hash('sha256', $reference), 0, 24),
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_records_flash'] = $exception->getMessage();
            $this->securityLogger->event('pickupsheet.record_delete', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_pickup_records_flash'] = 'The pickup sheet could not be deleted. Check MySQL and try again.';
            $this->securityLogger->event('pickupsheet.record_delete', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions');
    }

    public function users(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $flash = $_SESSION['_records_users_flash'] ?? null;
        $errors = $_SESSION['_records_users_errors'] ?? [];
        $old = $_SESSION['_records_users_old'] ?? [];
        unset($_SESSION['_records_users_flash'], $_SESSION['_records_users_errors'], $_SESSION['_records_users_old']);

        $accounts = [];
        $administratorAccounts = $this->recordsAccess->environmentAdministrators();
        $loginMethods = $this->effectiveLoginMethods();
        $mfaStatuses = [];
        try {
            $accounts = $this->recordsUserService->accounts($authorization);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors = ['Account storage could not be initialized. Confirm that the MySQL user can create tables, or apply migration 005 in phpMyAdmin.'];
        }
        $mfaSubjects = array_merge(
            array_map(static fn (RecordsPrincipal $account): string => $account->securitySubject(), $administratorAccounts),
            array_map(static fn ($account): string => 'local-user:' . $account->id, $accounts),
        );
        if ($mfaSubjects !== [] && $this->localMfa?->isEnabled() === true && $this->localMfa->isConfigured()) {
            try {
                $mfaStatuses = $this->localMfa->statuses($mfaSubjects);
            } catch (Throwable $exception) {
                error_log('Local account 2FA status could not be loaded: ' . $exception->getMessage());
                $errors[] = 'Two-factor status could not be loaded. Apply migration 015 and check MySQL.';
            }
        }

        $body = $this->view->render('pickupsheet/users', [
            'pageTitle' => 'Pickup records access',
            'pageDescription' => 'Review local administrators and manage pickup records accounts.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'accounts' => $accounts,
            'administratorAccounts' => $administratorAccounts,
            'flash' => is_string($flash) ? $flash : null,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'recordsUsername' => $authorization->username,
            'recordsFullName' => $authorization->fullName(),
            'recordsIdentityProvider' => $authorization->identityProvider,
            'loginMethods' => $loginMethods,
            'mfaStatuses' => $mfaStatuses,
            'localMfaEnabled' => $this->localMfa?->isEnabled() === true,
            'localMfaConfigured' => $this->localMfa?->isConfigured() === true,
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function createUser(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        $input = [
            'username' => $request->input('username'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'role' => $request->input('role'),
            'active' => $request->input('active'),
            'password' => $request->rawInput('password'),
            'password_confirmation' => $request->rawInput('password_confirmation'),
        ];

        try {
            $account = $this->recordsUserService->create($input, $authorization);
            $_SESSION['_records_users_flash'] = sprintf('Account %s created as %s.', $account->username, $account->role);
            $this->securityLogger->event('pickupsheet.records_user_create', $request, 'accepted', [
                'target_id' => substr(hash('sha256', $account->username), 0, 24),
                'target_role' => $account->role,
                'active' => $account->active,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $_SESSION['_records_users_old'] = [
                'username' => $input['username'],
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'role' => $input['role'],
                'active' => $input['active'],
            ];
            $this->securityLogger->event('pickupsheet.records_user_create', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['The account could not be created. Check the MySQL connection and try again.'];
            $this->securityLogger->event('pickupsheet.records_user_create', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function updateUser(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        $input = [
            'id' => $request->input('id'),
            'username' => $request->input('username'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'role' => $request->input('role'),
            'active' => $request->input('active'),
            'password' => $request->rawInput('password'),
            'password_confirmation' => $request->rawInput('password_confirmation'),
        ];

        try {
            $account = $this->recordsUserService->update($input, $authorization);
            $_SESSION['_records_users_flash'] = sprintf('Account %s updated.', $account->username);
            $this->securityLogger->event('pickupsheet.records_user_update', $request, 'accepted', [
                'target_id' => substr(hash('sha256', $account->username), 0, 24),
                'target_role' => $account->role,
                'active' => $account->active,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.records_user_update', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['The account could not be updated. Check the MySQL connection and try again.'];
            $this->securityLogger->event('pickupsheet.records_user_update', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function setUserStatus(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        try {
            $active = $request->input('active');
            if ($active === '0' && $request->input('confirm_status') !== '1') {
                throw new InvalidArgumentException('Confirm that this managed account should be disabled.');
            }
            $account = $this->recordsUserService->setStatus($request->input('id'), $active, $authorization);
            $statusLabel = $account->active ? 'enabled' : 'disabled';
            $_SESSION['_records_users_flash'] = sprintf('Account %s %s.', $account->username, $statusLabel);
            $this->securityLogger->event('pickupsheet.records_user_status', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'target_id' => substr(hash('sha256', $account->username), 0, 24),
                'target_role' => $account->role,
                'active' => $account->active,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.records_user_status', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['The managed account status could not be changed. Check the MySQL connection and try again.'];
            $this->securityLogger->event('pickupsheet.records_user_status', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function deleteUser(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        try {
            if ($request->input('confirm_delete') !== '1') {
                throw new InvalidArgumentException('Confirm the local account deletion and try again.');
            }
            $account = $this->recordsUserService->delete($request->input('id'), $authorization);
            if ($this->localMfa?->isConfigured() === true) {
                try {
                    $this->localMfa->reset('local-user:' . $account->id, $this->actorId($authorization));
                } catch (Throwable $exception) {
                    error_log('Deleted local account 2FA cleanup failed: ' . $exception->getMessage());
                }
            }
            $_SESSION['_records_users_flash'] = sprintf('Local account %s deleted.', $account->username);
            $this->securityLogger->event('pickupsheet.records_user_delete', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'target_id' => substr(hash('sha256', $account->username), 0, 24),
                'target_role' => $account->role,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.records_user_delete', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['The local account could not be deleted. Check the MySQL connection and try again.'];
            $this->securityLogger->event('pickupsheet.records_user_delete', $request, 'failed');
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function resetUserMfa(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }
        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        try {
            if ($request->input('confirm_reset') !== '1') {
                throw new InvalidArgumentException('Confirm the two-factor reset and try again.');
            }
            if ($this->localMfa?->isConfigured() !== true) {
                throw new RuntimeException('Local 2FA is not configured.');
            }
            $account = $this->recordsUserService->account($request->input('id'), $authorization);
            $reauthenticationMethod = $this->reauthenticateMfaReset($request, $authorization);
            if (!$this->localMfa->reset('local-user:' . $account->id, $this->actorId($authorization))) {
                throw new InvalidArgumentException('Two-factor authentication is not enrolled for that account.');
            }
            $this->recordsSession->renewId();
            $this->csrf->rotate();
            $_SESSION['_records_users_flash'] = sprintf('Two-factor authentication reset for %s. It must be enrolled at the next sign-in.', $account->username);
            $this->securityLogger->event('pickupsheet.records_user_mfa_reset', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'target_id' => substr(hash('sha256', $account->username), 0, 24),
                'reauthenticated_with' => $reauthenticationMethod,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.records_user_mfa_reset', $request, 'denied');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['Two-factor authentication could not be reset. Check migration 015 and MySQL.'];
            $this->securityLogger->event('pickupsheet.records_user_mfa_reset', $request, 'failed');
        } catch (Throwable $exception) {
            error_log('Managed-user 2FA reset failed: ' . $exception->getMessage());
            $_SESSION['_records_users_errors'] = ['Two-factor authentication could not be reset. Please try again.'];
            $this->securityLogger->event('pickupsheet.records_user_mfa_reset', $request, 'failed');
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function confirmUserMfaReset(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }
        try {
            if ($this->localMfa?->isConfigured() !== true) {
                throw new RuntimeException('Local 2FA is not configured.');
            }
            $account = $this->recordsUserService->account($request->queryString('id'), $authorization);
            if (!$this->localMfa->isEnrolled('local-user:' . $account->id)) {
                throw new InvalidArgumentException('Two-factor authentication is not enrolled for that account.');
            }
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['Two-factor authentication could not be loaded. Check migration 015 and MySQL.'];
            return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
        } catch (Throwable $exception) {
            error_log('Managed-user 2FA confirmation failed: ' . $exception->getMessage());
            $_SESSION['_records_users_errors'] = ['Two-factor authentication could not be loaded. Please try again.'];
            return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
        }

        $body = $this->view->render('pickupsheet/admin-mfa-reset', [
            'pageTitle' => 'Confirm 2FA reset',
            'pageDescription' => 'Reauthenticate before resetting a managed account authenticator.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'principal' => $authorization,
            'account' => $account,
            'requiresLocalReauthentication' => $authorization->identityProvider === 'local',
            'ssoAuthenticationFresh' => $this->recordsSession->authenticatedWithin(300),
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function updateLoginMethods(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        $ajax = strcasecmp($request->header('X-Requested-With'), 'XMLHttpRequest') === 0;
        try {
            if ($this->loginMethodSettings === null) {
                throw new RuntimeException('Login-method settings storage is unavailable.');
            }
            $settings = $this->loginMethodSettings->update(
                $request->input('local_login_enabled') === '1',
                $request->input('jumpcloud_login_enabled') === '1',
                $this->actorId($authorization),
            );
            $message = 'Sign-in methods updated.';
            $this->securityLogger->event('pickupsheet.login_methods_update', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
                'local_login_enabled' => $settings->localLoginEnabled,
                'jumpcloud_login_enabled' => $settings->jumpCloudLoginEnabled,
            ]);
            if ($ajax) {
                return Response::json([
                    'ok' => true,
                    'message' => $message,
                    'localLoginEnabled' => $settings->localLoginEnabled,
                    'jumpCloudLoginEnabled' => $settings->jumpCloudLoginEnabled,
                    'updatedAt' => $settings->updatedAt,
                ], 200, $this->privateHeaders());
            }
            $_SESSION['_records_users_flash'] = $message;
        } catch (InvalidArgumentException $exception) {
            $this->securityLogger->event('pickupsheet.login_methods_update', $request, 'denied');
            if ($ajax) {
                return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422, $this->privateHeaders());
            }
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $message = 'Sign-in methods could not be saved. Apply migration 014 and check MySQL.';
            $this->securityLogger->event('pickupsheet.login_methods_update', $request, 'failed');
            if ($ajax) {
                return Response::json(['ok' => false, 'message' => $message], 503, $this->privateHeaders());
            }
            $_SESSION['_records_users_errors'] = [$message];
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function resetAdminPassword(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        if ($authorization->identityProvider !== 'local') {
            $_SESSION['_records_users_errors'] = ['JumpCloud passwords are managed in JumpCloud and cannot be reset from Pickupsheet.'];
            $this->securityLogger->event('pickupsheet.admin_password_reset', $request, 'denied', [
                'actor_id' => $this->actorId($authorization),
                'identity_provider' => $authorization->identityProvider,
            ]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
        }

        $writeDenied = $this->recordsUserWriteGuard($request);
        if ($writeDenied !== null) {
            return $writeDenied;
        }

        $currentPassword = $request->rawInput('current_password');
        $verified = $this->recordsAccess->authenticateCredentials($authorization->username, $currentPassword);
        if ($verified === null || $verified->role !== 'admin') {
            $_SESSION['_records_users_errors'] = ['The current administrator password is incorrect.'];
            $this->securityLogger->event('pickupsheet.admin_password_reset', $request, 'denied', [
                'actor_id' => $this->actorId($authorization),
            ]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
        }

        try {
            $this->recordsUserService->resetAdministratorPassword([
                'password' => $request->rawInput('password'),
                'password_confirmation' => $request->rawInput('password_confirmation'),
            ], $authorization);
            $this->securityLogger->event('pickupsheet.admin_password_reset', $request, 'accepted', [
                'actor_id' => $this->actorId($authorization),
            ]);
            $_SESSION['_pickup_login_flash'] = 'Administrator password reset. Sign in with the new password.';
            $this->recordsSession->logout();
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_records_users_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.admin_password_reset', $request, 'denied', [
                'actor_id' => $this->actorId($authorization),
            ]);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_records_users_errors'] = ['The administrator password could not be reset. Check MySQL and try again.'];
            $this->securityLogger->event('pickupsheet.admin_password_reset', $request, 'failed', [
                'actor_id' => $this->actorId($authorization),
            ]);
        }

        return Response::redirect($request->basePath . '/dhl/pickupsheet/submissions/users');
    }

    public function print(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'print');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $pickupSheet = $this->service->findByReference($request->queryString('reference'));
        if ($pickupSheet === null) {
            return Response::html('Pickup sheet not found.', 404, $this->privateHeaders());
        }

        $body = $this->view->render('pickupsheet/print', [
            'pageTitle' => $pickupSheet->referenceNumber,
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'pickupSheet' => $pickupSheet,
        ], 'layouts/print');

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function export(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'export');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $pickupSheet = $this->service->findByReference($request->queryString('reference'));
        if ($pickupSheet === null) {
            return Response::html('Pickup sheet not found.', 404, $this->privateHeaders());
        }

        return Response::download(
            (new XlsxWriter())->create(
                ['Consignor', 'AWB number', 'Destination', 'Amount (XAF)', 'Pieces', 'Weight (kg)', 'Time collected', 'Checked by'],
                array_map(static fn ($shipment): array => [
                    $shipment->consignor,
                    $shipment->awbNumber,
                    $shipment->destination,
                    $shipment->amountXaf,
                    $shipment->pieces,
                    (float) $shipment->weightKg,
                    $shipment->collectionTime,
                    $shipment->checkedBy,
                ], $pickupSheet->shipments),
                'SHIPMENT TOTAL',
                4,
                $pickupSheet->totalCashReceivedXaf,
                [28, 16, 16, 18, 14, 14, 18, 24],
            ),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $pickupSheet->referenceNumber . '.xlsx',
        );
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    private function effectiveLoginMethods(): LoginMethodSettings
    {
        if ($this->loginMethodSettings !== null) {
            try {
                return $this->loginMethodSettings->current();
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
            }
        }

        $localConfigured = (bool) ($this->config['local_login_enabled'] ?? true);
        $jumpCloudConfigured = (bool) ($this->config['jumpcloud_oidc_configured'] ?? false);
        return new LoginMethodSettings(
            $localConfigured,
            $jumpCloudConfigured,
            $localConfigured,
            $jumpCloudConfigured,
            (bool) ($this->config['cloudflare_access_configured'] ?? false),
        );
    }

    private function authorizeRecords(Request $request, string $action, bool $logGranted = true): RecordsPrincipal|Response
    {
        $resource = $request->queryString('reference');
        $context = ['action' => $action];
        if ($resource !== '') {
            $context['resource_id'] = substr(hash('sha256', $resource), 0, 24);
        }

        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal !== null) {
            $context['actor_id'] = substr(hash('sha256', $principal->username), 0, 24);
            $context['role'] = $principal->role;

            if ($principal->can($action)) {
                if ($logGranted) {
                    $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
                }
                return $principal;
            }

            $this->securityLogger->event('pickupsheet.records_access', $request, 'forbidden', $context);

            return Response::html('You do not have permission to perform this records action.', 403, $this->privateHeaders());
        }

        $context['configured'] = $this->recordsAccess->isConfigured();
        $this->securityLogger->event('pickupsheet.records_access', $request, 'denied', $context);

        return Response::redirect($request->basePath . '/dhl/pickupsheet/login', 302);
    }

    /** @return array<string, mixed> */
    private function submissionRecords(Request $request, RecordsPrincipal $principal): array
    {
        $search = trim($request->queryString('q'));
        $pagination = [
            'items' => [],
            'page' => 1,
            'perPage' => 10,
            'totalRecords' => 0,
            'totalPages' => 1,
            'unpaidBalanceXaf' => 0,
        ];
        $errors = [];

        if ($this->pickupOperational) {
            try {
                $pagination = $this->service->paginated($this->pageNumber($request), 10, $search);
            } catch (InvalidArgumentException $exception) {
                $errors = [$exception->getMessage()];
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                $errors = ['Submitted pickup sheets could not be loaded. Please try again later.'];
            }
        }

        return [
            'basePath' => $request->basePath,
            'pickupOperational' => $this->pickupOperational,
            'pickupSheets' => $pagination['items'],
            'pagination' => $pagination,
            'search' => $search,
            'errors' => $errors,
            'canPrint' => $principal->can('print'),
            'canExport' => $principal->can('export'),
            'canManage' => $principal->can('manage'),
            'canCrmView' => $principal->can('crm_view'),
            'canEdit' => $principal->can('edit'),
            'canMarkPaid' => $principal->can('mark_paid'),
            'canDelete' => $principal->can('delete'),
            'recordsRole' => $principal->role,
            'recordsUsername' => $principal->username,
            'recordsFullName' => $principal->fullName(),
            'csrfToken' => $this->csrf->token(),
        ];
    }

    /**
     * @param list<array{date: string, sheetCount: int, shipmentCount: int, totalCashXaf: int}> $activity
     * @return list<array{date: string, sheetCount: int, shipmentCount: int, totalCashXaf: int}>
     */
    private function completeActivity(array $activity, int $days): array
    {
        $byDate = [];
        foreach ($activity as $row) {
            $byDate[$row['date']] = $row;
        }

        $complete = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime('-' . $offset . ' days'));
            $complete[] = $byDate[$date] ?? [
                'date' => $date,
                'sheetCount' => 0,
                'shipmentCount' => 0,
                'totalCashXaf' => 0,
            ];
        }
        return $complete;
    }

    /**
     * @param list<array<string, mixed>> $logs
     * @param list<object> $accounts
     * @param list<array<string, mixed>> $userActivity
     * @return list<array<string, mixed>>
     */
    private function auditLogsWithIdentity(
        array $logs,
        RecordsPrincipal $currentUser,
        array $accounts,
        array $userActivity,
    ): array {
        $knownUsers = [];
        $register = static function (
            string $username,
            string $fullName,
            string $role,
            string $identityProvider,
        ) use (&$knownUsers): void {
            if ($username === '') {
                return;
            }
            $knownUsers[substr(hash('sha256', $username), 0, 24)] = [
                'username' => $username,
                'fullName' => $fullName,
                'role' => $role,
                'identityProvider' => $identityProvider,
            ];
        };

        $register(
            $currentUser->username,
            $currentUser->fullName(),
            $currentUser->role,
            $currentUser->identityProvider,
        );
        foreach ($accounts as $account) {
            if (isset($account->username) && method_exists($account, 'fullName')) {
                $register(
                    (string) $account->username,
                    (string) $account->fullName(),
                    (string) ($account->role ?? ''),
                    'local',
                );
            }
        }
        foreach ($userActivity as $user) {
            $register(
                (string) ($user['username'] ?? ''),
                (string) ($user['fullName'] ?? ''),
                (string) ($user['role'] ?? ''),
                (string) ($user['identityProvider'] ?? ''),
            );
        }

        $requestActors = [];
        foreach ($logs as $log) {
            $requestId = (string) ($log['requestId'] ?? '');
            $actorId = (string) ($log['actorId'] ?? '');
            if ($requestId !== '' && $actorId !== '') {
                $requestActors[$requestId] = $actorId;
            }
        }

        foreach ($logs as &$log) {
            $requestId = (string) ($log['requestId'] ?? '');
            $actorId = (string) ($log['actorId'] ?? '');
            if ($actorId === '' && isset($requestActors[$requestId])) {
                $actorId = $requestActors[$requestId];
                $log['actorId'] = $actorId;
            }

            $identity = $knownUsers[$actorId] ?? null;
            $log['actorName'] = is_array($identity)
                ? $identity['fullName']
                : ($actorId === '' ? 'Unauthenticated' : 'Unresolved account');
            $log['actorUsername'] = is_array($identity)
                ? $identity['username']
                : ($actorId === '' ? '' : 'ID ' . substr($actorId, 0, 10));
            $log['role'] = (string) ($log['role'] ?? '') ?: (is_array($identity) ? $identity['role'] : '');
            $log['identityProvider'] = (string) ($log['identityProvider'] ?? '')
                ?: (is_array($identity) ? $identity['identityProvider'] : '');

            $targetId = (string) ($log['targetId'] ?? '');
            $log['targetName'] = isset($knownUsers[$targetId]) ? $knownUsers[$targetId]['fullName'] : '';
        }
        unset($log);

        return $logs;
    }

    private function recordsUserWriteGuard(Request $request): ?Response
    {
        $rateLimitResponse = $this->rateLimit($request, 'pickup-records-users-write', 30, 3600);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.records_users_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        return null;
    }

    private function reauthenticateMfaReset(Request $request, RecordsPrincipal $principal): string
    {
        if ($principal->identityProvider !== 'local') {
            if (!$this->recordsSession->authenticatedWithin(300)) {
                throw new InvalidArgumentException('Sign out and sign in with your identity provider again before resetting another user\'s 2FA.');
            }
            return $principal->identityProvider . '_recent';
        }

        $verified = $this->recordsAccess->authenticateCredentials(
            $principal->username,
            $request->rawInput('current_password'),
        );
        if ($verified === null
            || $verified->identityProvider !== 'local'
            || !hash_equals($principal->securitySubject(), $verified->securitySubject())
            || !hash_equals($principal->authenticationVersion, $verified->authenticationVersion)) {
            throw new InvalidArgumentException('Enter your current administrator password.');
        }
        if ($this->localMfa?->isConfigured() !== true
            || !$this->localMfa->isEnrolled($principal->securitySubject())) {
            throw new InvalidArgumentException('Your administrator account must have two-factor authentication enrolled.');
        }
        $method = $this->localMfa->verify($principal->securitySubject(), $request->rawInput('code'));
        if ($method === null) {
            throw new InvalidArgumentException('Enter a valid administrator authenticator or recovery code.');
        }
        return $method;
    }

    private function pickupLifecycleWriteGuard(Request $request, string $action): ?Response
    {
        $rateLimitResponse = $this->rateLimit($request, 'pickup-records-' . $action, 30, 3600);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.' . $action . '_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }
        return null;
    }

    private function actorId(RecordsPrincipal $principal): string
    {
        return substr(hash('sha256', $principal->username), 0, 24);
    }

    /** @param list<mixed> $shipments @return list<mixed> */
    private function shipmentsCheckedByPrincipal(array $shipments, RecordsPrincipal $principal): array
    {
        $fullName = $principal->fullName();
        return array_map(static function (mixed $shipment) use ($fullName): mixed {
            if (!is_array($shipment)) {
                return $shipment;
            }
            $shipment['checked_by'] = $fullName;
            return $shipment;
        }, $shipments);
    }

    private function pageNumber(Request $request, string $parameter = 'page'): int
    {
        $page = $request->queryString($parameter, '1');
        return preg_match('/^[1-9][0-9]{0,8}$/', $page) ? (int) $page : 1;
    }

    /** @return array{items: array<never>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    private function emptyPagination(): array
    {
        return ['items' => [], 'page' => 1, 'perPage' => 10, 'totalRecords' => 0, 'totalPages' => 1];
    }

    private function rateLimit(
        Request $request,
        string $scope,
        int $limit,
        int $windowSeconds,
    ): ?Response {
        try {
            $retryAfter = $this->rateLimiter->consume($scope, $request->clientIdentifier(), $limit, $windowSeconds);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->securityLogger->event($scope . '.rate_limit', $request, 'failed');

            return Response::html('This service is temporarily unavailable. Please try again later.', 503, [
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($retryAfter > 0) {
            $this->securityLogger->event($scope . '.rate_limit', $request, 'denied', ['retry_after' => $retryAfter]);

            return Response::html('Too many requests. Please try again later.', 429, [
                'Cache-Control' => 'no-store',
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        return null;
    }

}
