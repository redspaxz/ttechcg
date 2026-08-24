<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
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
    ) {
    }

    public function index(Request $request): Response
    {
        $flash = $_SESSION['_pickup_flash'] ?? null;
        $errors = $_SESSION['_pickup_errors'] ?? [];
        $old = $_SESSION['_pickup_old'] ?? [];
        unset($_SESSION['_pickup_flash'], $_SESSION['_pickup_errors'], $_SESSION['_pickup_old']);

        return Response::html($this->view->render('pickupsheet/show', [
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
        ]));
    }

    public function store(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
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
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        if ($request->input('website') !== '') {
            $_SESSION['_pickup_flash'] = 'Pickup sheet saved.';
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        if (!$this->captcha->validate($request->input('captcha_nonce'), $request->input('captcha_answer'))) {
            $_SESSION['_pickup_errors'] = ['Please complete the human verification with the correct answer.'];
            $_SESSION['_pickup_old'] = $input;
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        $lastSubmissionAt = (int) ($_SESSION['_last_pickup_sheet_at'] ?? 0);
        if ($lastSubmissionAt > 0 && time() - $lastSubmissionAt < 10) {
            $_SESSION['_pickup_errors'] = ['Please wait a moment before saving another pickup sheet.'];
            $_SESSION['_pickup_old'] = $input;
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        try {
            $pickupSheet = $this->service->submit($input);
            $_SESSION['_pickup_flash'] = sprintf(
                'Pickup sheet %s saved with %d shipment%s and a total of %s XAF.',
                $pickupSheet->referenceNumber,
                $pickupSheet->shipmentCount(),
                $pickupSheet->shipmentCount() === 1 ? '' : 's',
                number_format($pickupSheet->totalCashReceivedXaf),
            );
            $_SESSION['_last_pickup_sheet_at'] = time();
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_errors'] = [$exception->getMessage()];
            $_SESSION['_pickup_old'] = $input;
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_pickup_errors'] = ['We could not save the pickup sheet. Please try again later.'];
            $_SESSION['_pickup_old'] = $input;
        }

        return Response::redirect($request->basePath . '/pickupsheet/');
    }

    public function submissions(Request $request): Response
    {
        $authorized = $this->viewerAuthorized();
        $errors = $_SESSION['_pickup_view_errors'] ?? [];
        unset($_SESSION['_pickup_view_errors']);
        $pickupSheets = [];

        if ($authorized && $this->pickupOperational) {
            try {
                $pickupSheets = $this->service->recent();
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                $errors = ['Submitted pickup sheets could not be loaded. Please try again later.'];
            }
        }

        $body = $this->view->render('pickupsheet/submissions', [
            'pageTitle' => 'Submitted pickup sheets',
            'pageDescription' => 'Protected pickup-sheet records and shipment exports.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'csrfToken' => $this->csrf->token(),
            'authorized' => $authorized,
            'viewKeyConfigured' => $this->viewKey() !== '',
            'pickupOperational' => $this->pickupOperational,
            'pickupSheets' => $pickupSheets,
            'errors' => is_array($errors) ? $errors : [],
        ]);

        return Response::html($body, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        $viewKey = $this->viewKey();
        if ($viewKey === '') {
            $_SESSION['_pickup_view_errors'] = ['Submitted-sheet access is not configured on this server.'];
            return Response::redirect($request->basePath . '/pickupsheet/submissions');
        }

        $attempts = $_SESSION['_pickup_view_attempts'] ?? ['count' => 0, 'last_at' => 0];
        $count = is_array($attempts) ? (int) ($attempts['count'] ?? 0) : 0;
        $lastAt = is_array($attempts) ? (int) ($attempts['last_at'] ?? 0) : 0;
        if ($count >= 5 && time() - $lastAt < 300) {
            $_SESSION['_pickup_view_errors'] = ['Too many access attempts. Please wait five minutes and try again.'];
            return Response::redirect($request->basePath . '/pickupsheet/submissions');
        }
        if ($lastAt > 0 && time() - $lastAt >= 300) {
            $count = 0;
        }

        if (!hash_equals($viewKey, $request->input('access_key'))) {
            $_SESSION['_pickup_view_attempts'] = ['count' => $count + 1, 'last_at' => time()];
            $_SESSION['_pickup_view_errors'] = ['The submitted-sheet access key is incorrect.'];
            return Response::redirect($request->basePath . '/pickupsheet/submissions');
        }

        session_regenerate_id(true);
        $_SESSION['_pickup_view_authorization'] = hash('sha256', $viewKey);
        unset($_SESSION['_pickup_view_attempts']);

        return Response::redirect($request->basePath . '/pickupsheet/submissions');
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        unset($_SESSION['_pickup_view_authorization']);
        session_regenerate_id(true);

        return Response::redirect($request->basePath . '/pickupsheet/submissions');
    }

    public function print(Request $request): Response
    {
        if (!$this->viewerAuthorized()) {
            return Response::redirect($request->basePath . '/pickupsheet/submissions');
        }

        $pickupSheet = $this->service->findByReference($request->queryString('reference'));
        if ($pickupSheet === null) {
            return Response::html('Pickup sheet not found.', 404, ['X-Robots-Tag' => 'noindex, nofollow']);
        }

        $body = $this->view->render('pickupsheet/print', [
            'pageTitle' => $pickupSheet->referenceNumber,
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'pickupSheet' => $pickupSheet,
        ], 'layouts/print');

        return Response::html($body, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function export(Request $request): Response
    {
        if (!$this->viewerAuthorized()) {
            return Response::redirect($request->basePath . '/pickupsheet/submissions');
        }

        $pickupSheet = $this->service->findByReference($request->queryString('reference'));
        if ($pickupSheet === null) {
            return Response::html('Pickup sheet not found.', 404, ['X-Robots-Tag' => 'noindex, nofollow']);
        }

        return Response::download(
            $this->excelCsv($pickupSheet),
            'text/csv; charset=UTF-8',
            $pickupSheet->referenceNumber . '.csv',
        );
    }

    private function viewerAuthorized(): bool
    {
        $viewKey = $this->viewKey();
        $authorization = $_SESSION['_pickup_view_authorization'] ?? '';

        return $viewKey !== ''
            && is_string($authorization)
            && hash_equals(hash('sha256', $viewKey), $authorization);
    }

    private function viewKey(): string
    {
        $viewKey = trim((string) ($this->config['pickup_view_key'] ?? ''));
        return strlen($viewKey) >= 16 ? $viewKey : '';
    }

    private function excelCsv(PickupSheet $pickupSheet): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare the pickup-sheet export.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Reference number', $pickupSheet->referenceNumber]);
        fputcsv($stream, ['Agent name', $this->safeSpreadsheetText($pickupSheet->agentName)]);
        fputcsv($stream, ['Collection date', $pickupSheet->collectionDate]);
        fputcsv($stream, ['Shipments collected', $pickupSheet->shipmentCount()]);
        fputcsv($stream, ['Total cash received', $pickupSheet->totalCashReceivedXaf, 'XAF']);
        fputcsv($stream, []);
        fputcsv($stream, ['#', 'Consignor', 'AWB number', 'Destination', 'Amount (XAF)', 'Pieces', 'Weight (kg)', 'Time collected', 'Checked by']);

        foreach ($pickupSheet->shipments as $shipment) {
            fputcsv($stream, [
                $shipment->lineNumber,
                $this->safeSpreadsheetText($shipment->consignor),
                '="' . $shipment->awbNumber . '"',
                $shipment->destination,
                $shipment->amountXaf,
                $shipment->pieces,
                $shipment->weightKg,
                $shipment->collectionTime,
                $this->safeSpreadsheetText($shipment->checkedBy),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the pickup-sheet export.');
        }

        return $contents;
    }

    private function safeSpreadsheetText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
    }
}
