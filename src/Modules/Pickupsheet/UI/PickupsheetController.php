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
        private readonly RecordsUserService $recordsUserService,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogger $securityLogger,
    ) {
    }

    public function index(Request $request): Response
    {
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
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function store(Request $request): Response
    {
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
            'shipments' => $request->arrayInput('shipments'),
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

    public function submissions(Request $request): Response
    {
        $authorization = $this->authorizeRecords($request, 'list');
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $records = $this->submissionRecords($request, $authorization);

        $body = $this->view->render('pickupsheet/submissions', array_merge($records, [
            'pageTitle' => 'Submitted pickup sheets',
            'pageDescription' => 'Pickup-sheet records and shipment exports.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
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
            $errors = ['Account storage is unavailable. Apply the records-user migration and check the MySQL connection.'];
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
            'role' => $request->input('role'),
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
            $_SESSION['_records_users_old'] = ['username' => $input['username'], 'role' => $input['role']];
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

        $principal = $this->recordsAccess->authenticate($request);
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

        $rateLimitResponse = $this->rateLimit($request, 'pickup-records-auth', 10, 900);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        $context['configured'] = $this->recordsAccess->isConfigured();
        $this->securityLogger->event('pickupsheet.records_access', $request, 'denied', $context);

        return Response::html('Authentication is required to access pickup-sheet records.', 401, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'WWW-Authenticate' => 'Basic realm="T&Tech Pickupsheet Records", charset="UTF-8"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
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
            'recordsRole' => $principal->role,
        ];
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
