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
        $authorizationResponse = $this->authorizeRecords($request, 'list');
        if ($authorizationResponse !== null) {
            return $authorizationResponse;
        }

        $errors = [];
        $pickupSheets = [];
        if ($this->pickupOperational) {
            try {
                $pickupSheets = $this->service->recent();
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                $errors = ['Submitted pickup sheets could not be loaded. Please try again later.'];
            }
        }

        $body = $this->view->render('pickupsheet/submissions', [
            'pageTitle' => 'Submitted pickup sheets',
            'pageDescription' => 'Pickup-sheet records and shipment exports.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'pickupOperational' => $this->pickupOperational,
            'pickupSheets' => $pickupSheets,
            'errors' => $errors,
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function print(Request $request): Response
    {
        $authorizationResponse = $this->authorizeRecords($request, 'print');
        if ($authorizationResponse !== null) {
            return $authorizationResponse;
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
        $authorizationResponse = $this->authorizeRecords($request, 'export');
        if ($authorizationResponse !== null) {
            return $authorizationResponse;
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

    private function authorizeRecords(Request $request, string $action): ?Response
    {
        $resource = $request->queryString('reference');
        $context = ['action' => $action];
        if ($resource !== '') {
            $context['resource_id'] = substr(hash('sha256', $resource), 0, 24);
        }

        if ($this->recordsAccess->allows($request)) {
            $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
            return null;
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
