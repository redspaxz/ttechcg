<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Csrf;
use App\Shared\Security\JumpCloudOidcProvider;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\SecurityLogger;
use App\Shared\View\View;
use RuntimeException;
use Throwable;

final class PickupsheetAuthController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly RecordsAccess $recordsAccess,
        private readonly RecordsSession $recordsSession,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogger $securityLogger,
        private readonly array $config,
        private readonly ?JumpCloudOidcProvider $jumpCloud = null,
    ) {
    }

    public function login(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal !== null) {
            return Response::redirect($this->destination($request, $principal));
        }

        $error = $_SESSION['_pickup_login_error'] ?? null;
        $flash = $_SESSION['_pickup_login_flash'] ?? null;
        $username = $_SESSION['_pickup_login_username'] ?? '';
        unset($_SESSION['_pickup_login_error'], $_SESSION['_pickup_login_flash'], $_SESSION['_pickup_login_username']);

        $body = $this->view->render('pickupsheet/login', [
            'pageTitle' => 'Pickupsheet login',
            'pageDescription' => 'Sign in to the secure Pickupsheet workspace.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'error' => is_string($error) ? $error : null,
            'flash' => is_string($flash) ? $flash : null,
            'username' => is_string($username) ? $username : '',
            'jumpCloudEnabled' => $this->jumpCloudConfigured(),
        ]);

        return Response::html($body, 200, $this->privateHeaders());
    }

    public function authenticate(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.login_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        try {
            $retryAfter = $this->rateLimiter->consume('pickup-login', $request->clientIdentifier(), 10, 900);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Login is temporarily unavailable. Please try again later.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->securityLogger->event('pickupsheet.login', $request, 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many login attempts. Please try again later.', 429, array_merge($this->privateHeaders(), [
                'Retry-After' => (string) $retryAfter,
            ]));
        }

        $username = $request->input('username');
        $principal = $this->recordsAccess->authenticateCredentials($username, $request->rawInput('password'));
        if ($principal === null) {
            $_SESSION['_pickup_login_error'] = 'The username or password is incorrect.';
            $_SESSION['_pickup_login_username'] = $username;
            $this->securityLogger->event('pickupsheet.login', $request, 'denied');
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        $this->recordsSession->login($principal);
        $this->securityLogger->event('pickupsheet.login', $request, 'accepted', [
            'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
            'role' => $principal->role,
        ]);
        return Response::redirect($this->destination($request, $principal));
    }

    public function jumpCloudStart(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal !== null) {
            return Response::redirect($this->destination($request, $principal));
        }

        if (!$this->jumpCloudConfigured()) {
            $_SESSION['_pickup_login_error'] = 'JumpCloud sign-in is not available.';
            $this->securityLogger->event('pickupsheet.jumpcloud_start', $request, 'unavailable');
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        try {
            $location = $this->jumpCloud?->authorizationUrl();
        } catch (Throwable $exception) {
            error_log('Pickupsheet JumpCloud start failed: ' . $exception->getMessage());
            $_SESSION['_pickup_login_error'] = 'JumpCloud sign-in could not be started. Please try again.';
            $this->securityLogger->event('pickupsheet.jumpcloud_start', $request, 'failed');
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        if (!is_string($location) || $location === '') {
            $_SESSION['_pickup_login_error'] = 'JumpCloud sign-in could not be started. Please try again.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        $this->securityLogger->event('pickupsheet.jumpcloud_start', $request, 'accepted');
        return Response::redirect($location, 302);
    }

    public function jumpCloudCallback(Request $request): Response
    {
        if (!$this->jumpCloudConfigured()) {
            $_SESSION['_pickup_login_error'] = 'JumpCloud sign-in is not available.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        try {
            $retryAfter = $this->rateLimiter->consume('pickup-jumpcloud-callback', $request->clientIdentifier(), 20, 900);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Login is temporarily unavailable. Please try again later.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->securityLogger->event('pickupsheet.jumpcloud_callback', $request, 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many login attempts. Please try again later.', 429, array_merge($this->privateHeaders(), [
                'Retry-After' => (string) $retryAfter,
            ]));
        }

        if ($request->queryString('error') !== '') {
            $_SESSION['_pickup_login_error'] = 'JumpCloud sign-in was cancelled or denied.';
            $this->securityLogger->event('pickupsheet.jumpcloud_callback', $request, 'denied');
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        try {
            $principal = $this->jumpCloud?->authenticate(
                $request->queryString('code'),
                $request->queryString('state'),
            );
        } catch (Throwable $exception) {
            error_log('Pickupsheet JumpCloud callback failed: ' . $exception->getMessage());
            $_SESSION['_pickup_login_error'] = 'JumpCloud could not verify this account or its Pickupsheet role.';
            $this->securityLogger->event('pickupsheet.jumpcloud_callback', $request, 'denied');
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        if (!$principal instanceof RecordsPrincipal) {
            $_SESSION['_pickup_login_error'] = 'JumpCloud could not verify this account or its Pickupsheet role.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        $this->recordsSession->login($principal);
        $this->securityLogger->event('pickupsheet.jumpcloud_callback', $request, 'accepted', [
            'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
            'role' => $principal->role,
            'identity_provider' => 'jumpcloud',
        ]);
        return Response::redirect($this->destination($request, $principal));
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.logout_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal !== null) {
            $this->securityLogger->event('pickupsheet.logout', $request, 'accepted', [
                'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
                'role' => $principal->role,
            ]);
        }
        $this->recordsSession->logout();
        return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
    }

    private function destination(Request $request, RecordsPrincipal $principal): string
    {
        if ($principal->can('dashboard')) {
            return $request->basePath . '/dhl/pickupsheet/dashboard';
        }
        if ($principal->can('create')) {
            return $request->basePath . '/dhl/pickupsheet/';
        }
        return $request->basePath . '/dhl/pickupsheet/submissions';
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    private function jumpCloudConfigured(): bool
    {
        return $this->jumpCloud?->isConfigured() === true;
    }

}
