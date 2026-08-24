<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
use App\Shared\Security\IdentityProvider;
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
        private readonly IdentityProvider $identityProvider,
        private readonly array $config,
        private readonly string $storageMode,
        private readonly bool $pickupOperational,
    ) {
    }

    public function index(Request $request): Response
    {
        $operator = $this->currentOperator();
        if ($operator === null) {
            return $this->loginPortal($request);
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
            'operatorName' => $operator['name'],
            'operatorUsername' => $operator['username'],
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function store(Request $request): Response
    {
        $operator = $this->currentOperator();
        if ($operator === null) {
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        $shipments = $request->arrayInput('shipments');
        foreach ($shipments as $index => $shipment) {
            if (is_array($shipment)) {
                $shipment['checked_by'] = $operator['name'];
                $shipments[$index] = $shipment;
            }
        }

        $input = [
            'agent_name' => $request->input('agent_name'),
            'collection_date' => $request->input('collection_date'),
            'shipments' => $shipments,
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
        $operator = $this->currentOperator();
        if ($operator === null) {
            return Response::redirect($request->basePath . '/pickupsheet/');
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
            'pageDescription' => 'Protected pickup-sheet records and shipment exports.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'csrfToken' => $this->csrf->token(),
            'pickupOperational' => $this->pickupOperational,
            'pickupSheets' => $pickupSheets,
            'errors' => $errors,
            'operatorName' => $operator['name'],
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        if (!$this->identityProvider->configured()) {
            $_SESSION['_pickup_login_errors'] = ['JumpCloud sign-in is not configured on this server.'];
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        $state = $this->randomBase64Url(32);
        $nonce = $this->randomBase64Url(32);
        $codeVerifier = $this->randomBase64Url(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $_SESSION['_pickup_oidc_transaction'] = [
            'state_hash' => hash('sha256', $state),
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'created_at' => time(),
        ];

        try {
            return Response::redirect($this->identityProvider->authorizationUrl($state, $nonce, $codeChallenge));
        } catch (RuntimeException $exception) {
            unset($_SESSION['_pickup_oidc_transaction']);
            error_log($exception->__toString());
            $_SESSION['_pickup_login_errors'] = ['JumpCloud sign-in could not be started. Please try again.'];
            return Response::redirect($request->basePath . '/pickupsheet/');
        }
    }

    public function loginCallback(Request $request): Response
    {
        $transaction = $_SESSION['_pickup_oidc_transaction'] ?? null;
        unset($_SESSION['_pickup_oidc_transaction']);

        $state = $request->queryString('state');
        if (!is_array($transaction)
            || !is_string($transaction['state_hash'] ?? null)
            || !is_string($transaction['nonce'] ?? null)
            || !is_string($transaction['code_verifier'] ?? null)
            || !is_int($transaction['created_at'] ?? null)
            || $transaction['created_at'] > time() + 60
            || time() - $transaction['created_at'] > 600
            || $state === ''
            || !hash_equals($transaction['state_hash'], hash('sha256', $state))
        ) {
            $_SESSION['_pickup_login_errors'] = ['The JumpCloud login transaction is invalid or expired. Please try again.'];
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        if ($request->queryString('error') !== '') {
            $_SESSION['_pickup_login_errors'] = ['JumpCloud sign-in was not completed.'];
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        try {
            $identity = $this->identityProvider->authenticate(
                $request->queryString('code'),
                $transaction['code_verifier'],
                $transaction['nonce'],
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_pickup_login_errors'] = ['JumpCloud could not verify your identity. Please try again or contact an administrator.'];
            return Response::redirect($request->basePath . '/pickupsheet/');
        }

        session_regenerate_id(true);
        $_SESSION['_pickup_operator'] = [
            'sub' => $identity['sub'],
            'username' => $identity['username'],
            'name' => $identity['name'],
            'email' => $identity['email'],
            'expires_at' => $identity['expires_at'],
            'fingerprint' => $this->identityProvider->fingerprint(),
        ];
        unset($_SESSION['_pickup_login_errors']);

        return Response::redirect($request->basePath . '/pickupsheet/');
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        unset($_SESSION['_pickup_operator'], $_SESSION['_pickup_oidc_transaction']);
        session_regenerate_id(true);

        return Response::redirect($request->basePath . '/pickupsheet/');
    }

    public function print(Request $request): Response
    {
        if ($this->currentOperator() === null) {
            return Response::redirect($request->basePath . '/pickupsheet/');
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

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function export(Request $request): Response
    {
        if ($this->currentOperator() === null) {
            return Response::redirect($request->basePath . '/pickupsheet/');
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

    /** @return array{username: string, name: string}|null */
    private function currentOperator(): ?array
    {
        $sessionOperator = $_SESSION['_pickup_operator'] ?? null;
        if (!$this->identityProvider->configured()
            || !is_array($sessionOperator)
            || !is_string($sessionOperator['sub'] ?? null)
            || !is_string($sessionOperator['username'] ?? null)
            || !is_string($sessionOperator['name'] ?? null)
            || !is_string($sessionOperator['fingerprint'] ?? null)
            || !is_int($sessionOperator['expires_at'] ?? null)
            || $sessionOperator['expires_at'] <= time()
            || !hash_equals($this->identityProvider->fingerprint(), $sessionOperator['fingerprint'])
        ) {
            unset($_SESSION['_pickup_operator']);
            return null;
        }

        return ['username' => $sessionOperator['username'], 'name' => $sessionOperator['name']];
    }

    private function loginPortal(Request $request): Response
    {
        $errors = $_SESSION['_pickup_login_errors'] ?? [];
        unset($_SESSION['_pickup_login_errors']);

        $body = $this->view->render('pickupsheet/login', [
            'pageTitle' => 'Pickupsheet operator login',
            'pageDescription' => 'Secure operator access to Pickupsheet.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
            'csrfToken' => $this->csrf->token(),
            'loginConfigured' => $this->identityProvider->configured(),
            'errors' => is_array($errors) ? $errors : [],
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    private function randomBase64Url(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
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
