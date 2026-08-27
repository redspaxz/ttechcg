<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
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
        ]);

        return Response::html($body, 200, $this->privateHeaders());
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
        $recentSheets = [];
        $accounts = [];
        $errors = [];

        try {
            $summary = $this->service->summary();
            $activity = $this->completeActivity($this->service->activityByDay(14), 14);
            $destinations = $this->service->topDestinations(5);
            $recentSheets = $this->service->recent(8);
            $accounts = $this->recordsUserService->accounts($authorization);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors = ['Dashboard activity could not be loaded. Check the MySQL connection and account schema.'];
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
            'recentSheets' => $recentSheets,
            'accounts' => $accounts,
            'errors' => $errors,
            'recordsUsername' => $authorization->username,
            'recordsRole' => $authorization->role,
            'recordsFullName' => $authorization->fullName(),
        ]);

        return Response::html($body, 200, $this->privateHeaders());
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
        try {
            $accounts = $this->recordsUserService->accounts($authorization);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $errors = ['Account storage could not be initialized. Confirm that the MySQL user can create tables, or apply migration 005 in phpMyAdmin.'];
        }

        $body = $this->view->render('pickupsheet/users', [
            'pageTitle' => 'Pickup records access',
            'pageDescription' => 'Manage lower-tier pickup records accounts.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'accounts' => $accounts,
            'flash' => is_string($flash) ? $flash : null,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'recordsUsername' => $authorization->username,
            'recordsFullName' => $authorization->fullName(),
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
                'role' => $account->role,
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
                'role' => $account->role,
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

    public function resetAdminPassword(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'manage');
        if ($authorization instanceof Response) {
            return $authorization;
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

    private function authorizeRecords(Request $request, string $action): RecordsPrincipal|Response
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
                $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
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
        $pagination = [
            'items' => [],
            'page' => 1,
            'perPage' => 10,
            'totalRecords' => 0,
            'totalPages' => 1,
        ];
        $errors = [];

        if ($this->pickupOperational) {
            try {
                $pagination = $this->service->paginated($this->pageNumber($request), 10);
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
            'errors' => $errors,
            'canPrint' => $principal->can('print'),
            'canExport' => $principal->can('export'),
            'canManage' => $principal->can('manage'),
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

    private function pageNumber(Request $request): int
    {
        $page = $request->queryString('page', '1');
        return preg_match('/^[1-9][0-9]{0,8}$/', $page) ? (int) $page : 1;
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
