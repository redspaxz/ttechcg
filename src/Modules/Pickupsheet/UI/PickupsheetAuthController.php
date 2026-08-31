<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\CloudflareAccessProvider;
use App\Shared\Security\Csrf;
use App\Shared\Security\JumpCloudOidcProvider;
use App\Shared\Security\LoginMethodSettings;
use App\Shared\Security\LoginMethodSettingsService;
use App\Shared\Security\LocalMfaService;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\SecurityLogger;
use App\Shared\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PickupsheetAuthController
{
    private const MFA_PENDING_KEY = '_pickup_mfa_pending';
    private const MFA_SETUP_SECRET_KEY = '_pickup_mfa_setup_secret';
    private const MFA_RECOVERY_CODES_KEY = '_pickup_mfa_recovery_codes';
    private const SETTINGS_MFA_SETUP_SECRET_KEY = '_pickup_settings_mfa_setup_secret';
    private const SETTINGS_MFA_RECOVERY_CODES_KEY = '_pickup_settings_mfa_recovery_codes';
    private const SETTINGS_MFA_REPLACEMENT_KEY = '_pickup_settings_mfa_replacement';

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
        private readonly ?CloudflareAccessProvider $cloudflareAccess = null,
        private readonly ?LoginMethodSettingsService $loginMethodSettings = null,
        private readonly ?LocalMfaService $localMfa = null,
    ) {
    }

    public function login(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal !== null) {
            return Response::redirect($this->destination($request, $principal));
        }
        $this->clearPendingMfa();

        $accessToken = $request->header('Cf-Access-Jwt-Assertion');
        if ($request->queryString('local') !== '1' && $accessToken !== '' && $this->cloudflareAccessConfigured()) {
            try {
                $retryAfter = $this->rateLimiter->consume('pickup-cloudflare-access', $request->clientIdentifier(), 20, 900);
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                return Response::html('Login is temporarily unavailable. Please try again later.', 503, $this->privateHeaders());
            }
            if ($retryAfter > 0) {
                $this->securityLogger->event('pickupsheet.cloudflare_access', $request, 'rate_limited', ['retry_after' => $retryAfter]);
                return Response::html('Too many login attempts. Please try again later.', 429, array_merge($this->privateHeaders(), [
                    'Retry-After' => (string) $retryAfter,
                ]));
            }

            try {
                $principal = $this->cloudflareAccess?->authenticate($accessToken);
            } catch (Throwable $exception) {
                error_log('Pickupsheet Cloudflare Access handoff failed: ' . $exception->getMessage());
                $_SESSION['_pickup_login_error'] = 'Cloudflare Access could not verify this account or its Pickupsheet role.';
                $this->securityLogger->event('pickupsheet.cloudflare_access', $request, 'denied');
            }

            if ($principal instanceof RecordsPrincipal) {
                $this->recordsSession->login($principal);
                $this->securityLogger->event('pickupsheet.cloudflare_access', $request, 'accepted', [
                    'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
                    'role' => $principal->role,
                    'identity_provider' => 'cloudflare_access',
                ]);
                return Response::redirect($this->destination($request, $principal));
            }
        }

        $error = $_SESSION['_pickup_login_error'] ?? null;
        $flash = $_SESSION['_pickup_login_flash'] ?? null;
        $username = $_SESSION['_pickup_login_username'] ?? '';
        unset($_SESSION['_pickup_login_error'], $_SESSION['_pickup_login_flash'], $_SESSION['_pickup_login_username']);

        $loginMethods = $this->loginMethods();
        $localLoginEnabled = $loginMethods->localLoginEnabled;
        $jumpCloudEnabled = $loginMethods->jumpCloudLoginEnabled;
        $loginMethodsAvailable = $localLoginEnabled || $jumpCloudEnabled;
        if (!$loginMethodsAvailable && !is_string($error)) {
            $error = 'No direct sign-in method is enabled. Contact the system administrator.';
        }

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
            'localLoginEnabled' => $localLoginEnabled,
            'jumpCloudEnabled' => $jumpCloudEnabled,
            'loginMethodsAvailable' => $loginMethodsAvailable,
            'localMfaEnabled' => $this->localMfa?->isEnabled() === true,
        ]);

        return Response::html($body, $loginMethodsAvailable ? 200 : 503, $this->privateHeaders());
    }

    public function authenticate(Request $request): Response
    {
        if (!$this->localLoginEnabled()) {
            $this->securityLogger->event('pickupsheet.login', $request, 'unavailable', ['method' => 'local']);
            return Response::html('Local sign-in is disabled.', 403, $this->privateHeaders());
        }

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

        if ($this->localMfa?->isEnabled() === true) {
            if (!$this->localMfa->isConfigured()) {
                $this->securityLogger->event('pickupsheet.local_mfa', $request, 'unavailable', [
                    'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
                ]);
                return Response::html('Local sign-in is temporarily unavailable. Contact the system administrator.', 503, $this->privateHeaders());
            }
            try {
                $this->startPendingMfa($principal);
                $this->securityLogger->event('pickupsheet.login_password', $request, 'accepted', [
                    'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
                    'role' => $principal->role,
                ]);
                return Response::redirect($request->basePath . '/dhl/pickupsheet/login/2fa');
            } catch (Throwable $exception) {
                error_log('Pickupsheet local 2FA start failed: ' . $exception->getMessage());
                $this->clearPendingMfa();
                return Response::html('Local sign-in is temporarily unavailable. Please try again later.', 503, $this->privateHeaders());
            }
        }

        $this->recordsSession->login($principal);
        $this->securityLogger->event('pickupsheet.login', $request, 'accepted', [
            'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
            'role' => $principal->role,
        ]);
        return Response::redirect($this->destination($request, $principal));
    }

    public function mfa(Request $request): Response
    {
        $signedIn = $this->recordsSession->principal($this->recordsAccess);
        if ($signedIn !== null) {
            return Response::redirect($this->destination($request, $signedIn));
        }
        $principal = $this->pendingMfaPrincipal();
        if ($principal === null || $this->localMfa?->isConfigured() !== true) {
            $this->clearPendingMfa();
            $_SESSION['_pickup_login_error'] = 'Your verification session expired. Sign in again.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        $error = $_SESSION['_pickup_mfa_error'] ?? null;
        unset($_SESSION['_pickup_mfa_error']);
        try {
            $enrolled = $this->localMfa->isEnrolled($principal->securitySubject());
            $setup = null;
            if (!$enrolled) {
                $secret = $_SESSION[self::MFA_SETUP_SECRET_KEY] ?? null;
                if (!is_string($secret) || preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
                    $setup = $this->localMfa->beginEnrollment($principal->username);
                    $_SESSION[self::MFA_SETUP_SECRET_KEY] = $setup['secret'];
                } else {
                    $setup = [
                        'secret' => $secret,
                        'formattedSecret' => trim(chunk_split($secret, 4, ' ')),
                        'otpauthUri' => $this->otpauthUri($principal->username, $secret),
                    ];
                }
            }
        } catch (Throwable $exception) {
            error_log('Pickupsheet local 2FA page failed: ' . $exception->getMessage());
            return Response::html('Two-factor verification is temporarily unavailable.', 503, $this->privateHeaders());
        }

        $body = $this->view->render('pickupsheet/mfa', [
            'pageTitle' => $enrolled ? 'Verify your sign-in' : 'Set up two-factor authentication',
            'pageDescription' => 'Complete local account two-factor authentication.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'principal' => $principal,
            'enrolled' => $enrolled,
            'setup' => $setup,
            'error' => is_string($error) ? $error : null,
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function verifyMfa(Request $request): Response
    {
        $principal = $this->pendingMfaPrincipal();
        if ($principal === null || $this->localMfa?->isConfigured() !== true) {
            $this->clearPendingMfa();
            $_SESSION['_pickup_login_error'] = 'Your verification session expired. Sign in again.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.local_mfa_csrf', $request, 'denied');
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        try {
            $retryAfter = $this->rateLimiter->consume(
                'pickup-local-mfa',
                $request->clientIdentifier() . ':' . substr(hash('sha256', $principal->securitySubject()), 0, 16),
                10,
                900,
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Verification is temporarily unavailable. Please try again later.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->securityLogger->event('pickupsheet.local_mfa', $request, 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many verification attempts. Please sign in again later.', 429, array_merge($this->privateHeaders(), [
                'Retry-After' => (string) $retryAfter,
            ]));
        }

        try {
            $enrolled = $this->localMfa->isEnrolled($principal->securitySubject());
            $recoveryCodes = [];
            $method = null;
            if ($enrolled) {
                $method = $this->localMfa->verify($principal->securitySubject(), $request->rawInput('code'));
            } else {
                $secret = $_SESSION[self::MFA_SETUP_SECRET_KEY] ?? null;
                if (is_string($secret)) {
                    $recoveryCodes = $this->localMfa->confirmEnrollment(
                        $principal->securitySubject(),
                        $principal->username,
                        $secret,
                        $request->rawInput('code'),
                        substr(hash('sha256', $principal->username), 0, 24),
                    );
                    $method = 'enrollment';
                }
            }
            if ($method === null) {
                return $this->failedMfaAttempt($request, $principal);
            }

            $currentPrincipal = $this->pendingMfaPrincipal();
            if ($currentPrincipal === null) {
                throw new RuntimeException('The local account changed during verification.');
            }
            $this->clearPendingMfa();
            $this->recordsSession->login($currentPrincipal);
            $this->securityLogger->event('pickupsheet.login', $request, 'accepted', [
                'actor_id' => substr(hash('sha256', $currentPrincipal->username), 0, 24),
                'role' => $currentPrincipal->role,
                'second_factor' => $method,
            ]);
            if ($recoveryCodes !== []) {
                $_SESSION[self::MFA_RECOVERY_CODES_KEY] = $recoveryCodes;
                return Response::redirect($request->basePath . '/dhl/pickupsheet/login/2fa/recovery-codes');
            }
            if ($method === 'recovery') {
                $_SESSION['_pickup_flash'] = 'A recovery code was used. Each recovery code works only once.';
            }
            return Response::redirect($this->destination($request, $currentPrincipal));
        } catch (InvalidArgumentException $exception) {
            return $this->failedMfaAttempt($request, $principal, $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Pickupsheet local 2FA verification failed: ' . $exception->getMessage());
            return Response::html('Two-factor verification is temporarily unavailable.', 503, $this->privateHeaders());
        }
    }

    public function recoveryCodes(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        $codes = $_SESSION[self::MFA_RECOVERY_CODES_KEY] ?? null;
        unset($_SESSION[self::MFA_RECOVERY_CODES_KEY]);
        if ($principal === null || $principal->identityProvider !== 'local' || !is_array($codes) || $codes === []) {
            return $principal === null
                ? Response::redirect($request->basePath . '/dhl/pickupsheet/login')
                : Response::redirect($this->destination($request, $principal));
        }

        $body = $this->view->render('pickupsheet/mfa-recovery', [
            'pageTitle' => 'Save your recovery codes',
            'pageDescription' => 'Store one-time local account recovery codes securely.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'principal' => $principal,
            'recoveryCodes' => array_values(array_filter($codes, 'is_string')),
            'destination' => $this->destination($request, $principal),
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function settings(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal === null) {
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }

        $flash = $_SESSION['_pickup_settings_flash'] ?? null;
        $errors = $_SESSION['_pickup_settings_errors'] ?? [];
        unset($_SESSION['_pickup_settings_flash'], $_SESSION['_pickup_settings_errors']);
        $localIdentity = $principal->identityProvider === 'local';
        $mfaAvailable = $localIdentity && $this->localMfa?->isConfigured() === true;
        $mfaEnrolled = false;
        $mfaReplacing = false;
        $setup = null;

        if ($mfaAvailable) {
            try {
                $mfaEnrolled = $this->localMfa->isEnrolled($principal->securitySubject());
                $mfaReplacing = $mfaEnrolled && $this->settingsMfaReplacementAuthorized($principal);
                if (!$mfaEnrolled || $mfaReplacing) {
                    $secret = $_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY] ?? null;
                    if (!is_string($secret) || preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
                        $setup = $this->localMfa->beginEnrollment($principal->username);
                        $_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY] = $setup['secret'];
                    } else {
                        $setup = [
                            'secret' => $secret,
                            'formattedSecret' => trim(chunk_split($secret, 4, ' ')),
                            'otpauthUri' => $this->otpauthUri($principal->username, $secret),
                        ];
                    }
                } else {
                    unset($_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY], $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY]);
                }
            } catch (Throwable $exception) {
                error_log('Pickupsheet user 2FA settings failed: ' . $exception->getMessage());
                $mfaAvailable = false;
                $errors[] = 'Two-factor settings are temporarily unavailable. Check the MySQL connection and migration 015.';
            }
        } else {
            unset($_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY], $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY]);
        }

        $identityProviderLabel = match ($principal->identityProvider) {
            'jumpcloud' => 'JumpCloud',
            'cloudflare_access' => 'JumpCloud via Cloudflare Access',
            default => 'Local account',
        };
        $body = $this->view->render('pickupsheet/settings', [
            'pageTitle' => 'User settings',
            'pageDescription' => 'Manage the signed-in Pickupsheet account and its two-factor authentication.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'csrfToken' => $this->csrf->token(),
            'principal' => $principal,
            'identityProviderLabel' => $identityProviderLabel,
            'localIdentity' => $localIdentity,
            'mfaEnabled' => $this->localMfa?->isEnabled() === true,
            'mfaAvailable' => $mfaAvailable,
            'mfaEnrolled' => $mfaEnrolled,
            'mfaReplacing' => $mfaReplacing,
            'setup' => $setup,
            'flash' => is_string($flash) ? $flash : null,
            'errors' => is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [],
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function enrollSettingsMfa(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal === null) {
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }
        $guard = $this->mfaSettingsWriteGuard($request, $principal);
        if ($guard !== null) {
            return $guard;
        }
        if ($principal->identityProvider !== 'local' || $this->localMfa?->isConfigured() !== true) {
            $_SESSION['_pickup_settings_errors'] = ['Two-factor authentication for this account is managed by its identity provider or is unavailable.'];
            return Response::redirect($request->basePath . '/dhl/pickupsheet/settings');
        }

        try {
            if (!$this->reauthenticates($principal, $request->rawInput('current_password'))) {
                throw new InvalidArgumentException('Enter your current local account password.');
            }
            $alreadyEnrolled = $this->localMfa->isEnrolled($principal->securitySubject());
            if ($alreadyEnrolled && !$this->settingsMfaReplacementAuthorized($principal)) {
                throw new InvalidArgumentException('Two-factor authentication is already enabled for this account.');
            }
            $secret = $_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY] ?? null;
            if (!is_string($secret) || preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
                throw new InvalidArgumentException('The authenticator setup expired. Reload User settings and try again.');
            }
            $recoveryCodes = $this->localMfa->confirmEnrollment(
                $principal->securitySubject(),
                $principal->username,
                $secret,
                $request->rawInput('code'),
                $this->actorId($principal),
            );
            unset($_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY], $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY]);
            $_SESSION[self::SETTINGS_MFA_RECOVERY_CODES_KEY] = $recoveryCodes;
            $this->securityLogger->event('pickupsheet.user_mfa_enroll', $request, 'accepted', [
                'actor_id' => $this->actorId($principal),
            ]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/settings/2fa/recovery-codes');
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_settings_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.user_mfa_enroll', $request, 'denied', [
                'actor_id' => $this->actorId($principal),
            ]);
        } catch (Throwable $exception) {
            error_log('Pickupsheet user 2FA enrollment failed: ' . $exception->getMessage());
            $_SESSION['_pickup_settings_errors'] = ['Two-factor enrollment is temporarily unavailable.'];
            $this->securityLogger->event('pickupsheet.user_mfa_enroll', $request, 'failed', [
                'actor_id' => $this->actorId($principal),
            ]);
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/settings');
    }

    public function resetSettingsMfa(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        if ($principal === null) {
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }
        $guard = $this->mfaSettingsWriteGuard($request, $principal);
        if ($guard !== null) {
            return $guard;
        }
        if ($principal->identityProvider !== 'local' || $this->localMfa?->isConfigured() !== true) {
            $_SESSION['_pickup_settings_errors'] = ['Two-factor authentication for this account is managed by its identity provider or is unavailable.'];
            return Response::redirect($request->basePath . '/dhl/pickupsheet/settings');
        }

        try {
            if ($request->input('confirm_reset') !== '1') {
                throw new InvalidArgumentException('Confirm that you want to replace your authenticator.');
            }
            if (!$this->reauthenticates($principal, $request->rawInput('current_password'))) {
                throw new InvalidArgumentException('Enter your current local account password.');
            }
            if (!$this->localMfa->isEnrolled($principal->securitySubject())) {
                throw new InvalidArgumentException('Two-factor authentication is not currently enrolled.');
            }
            $method = $this->localMfa->verify($principal->securitySubject(), $request->rawInput('code'));
            if ($method === null) {
                throw new InvalidArgumentException('Enter a valid current authenticator or recovery code.');
            }
            $setup = $this->localMfa->beginEnrollment($principal->username);
            $_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY] = $setup['secret'];
            $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY] = [
                'subject' => $principal->securitySubject(),
                'expires_at' => time() + 300,
            ];
            $_SESSION['_pickup_settings_flash'] = 'Replacement authorized for five minutes. Your current authenticator stays active until the new setup is confirmed.';
            $this->securityLogger->event('pickupsheet.user_mfa_reset', $request, 'accepted', [
                'actor_id' => $this->actorId($principal),
                'verified_with' => $method,
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_pickup_settings_errors'] = [$exception->getMessage()];
            $this->securityLogger->event('pickupsheet.user_mfa_reset', $request, 'denied', [
                'actor_id' => $this->actorId($principal),
            ]);
        } catch (Throwable $exception) {
            error_log('Pickupsheet user 2FA reset failed: ' . $exception->getMessage());
            $_SESSION['_pickup_settings_errors'] = ['Two-factor reset is temporarily unavailable.'];
            $this->securityLogger->event('pickupsheet.user_mfa_reset', $request, 'failed', [
                'actor_id' => $this->actorId($principal),
            ]);
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/settings');
    }

    public function settingsRecoveryCodes(Request $request): Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        $codes = $_SESSION[self::SETTINGS_MFA_RECOVERY_CODES_KEY] ?? null;
        unset($_SESSION[self::SETTINGS_MFA_RECOVERY_CODES_KEY]);
        if ($principal === null || $principal->identityProvider !== 'local' || !is_array($codes) || $codes === []) {
            return $principal === null
                ? Response::redirect($request->basePath . '/dhl/pickupsheet/login')
                : Response::redirect($request->basePath . '/dhl/pickupsheet/settings');
        }

        $body = $this->view->render('pickupsheet/mfa-recovery', [
            'pageTitle' => 'Save your recovery codes',
            'pageDescription' => 'Store one-time local account recovery codes securely.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'principal' => $principal,
            'recoveryCodes' => array_values(array_filter($codes, 'is_string')),
            'destination' => $request->basePath . '/dhl/pickupsheet/settings',
            'destinationLabel' => 'Return to User settings',
        ]);
        return Response::html($body, 200, $this->privateHeaders());
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
        $cloudflareIdentity = $principal?->identityProvider === 'cloudflare_access';
        if ($principal !== null) {
            $this->securityLogger->event('pickupsheet.logout', $request, 'accepted', [
                'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
                'role' => $principal->role,
            ]);
        }
        $this->recordsSession->logout();
        $this->clearPendingMfa();
        unset(
            $_SESSION[self::MFA_RECOVERY_CODES_KEY],
            $_SESSION[self::SETTINGS_MFA_SETUP_SECRET_KEY],
            $_SESSION[self::SETTINGS_MFA_RECOVERY_CODES_KEY],
            $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY],
            $_SESSION['_pickup_settings_flash'],
            $_SESSION['_pickup_settings_errors'],
        );
        if ($cloudflareIdentity) {
            return Response::redirect('/cdn-cgi/access/logout', 302);
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
    }

    private function destination(Request $request, RecordsPrincipal $principal): string
    {
        if ($principal->can('list')) {
            return $request->basePath . '/dhl/pickupsheet/submissions';
        }
        if ($principal->can('dashboard')) {
            return $request->basePath . '/dhl/pickupsheet/dashboard';
        }
        if ($principal->can('create')) {
            return $request->basePath . '/dhl/pickupsheet/';
        }
        return $request->basePath . '/dhl/pickupsheet/login';
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
        return $this->loginMethods()->jumpCloudLoginEnabled;
    }

    private function localLoginEnabled(): bool
    {
        return $this->loginMethods()->localLoginEnabled;
    }

    private function cloudflareAccessConfigured(): bool
    {
        return $this->cloudflareAccess?->isConfigured() === true;
    }

    private function loginMethods(): LoginMethodSettings
    {
        if ($this->loginMethodSettings !== null) {
            try {
                return $this->loginMethodSettings->current();
            } catch (Throwable $exception) {
                error_log('Pickupsheet login-method settings could not be loaded: ' . $exception->getMessage());
                return new LoginMethodSettings(
                    false,
                    false,
                    (bool) ($this->config['local_login_enabled'] ?? true),
                    $this->jumpCloud?->isConfigured() === true,
                    $this->cloudflareAccessConfigured(),
                );
            }
        }

        $localConfigured = (bool) ($this->config['local_login_enabled'] ?? true);
        $jumpCloudConfigured = $this->jumpCloud?->isConfigured() === true;
        return new LoginMethodSettings(
            $localConfigured,
            $jumpCloudConfigured,
            $localConfigured,
            $jumpCloudConfigured,
            $this->cloudflareAccessConfigured(),
        );
    }

    private function startPendingMfa(RecordsPrincipal $principal): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION[self::MFA_SETUP_SECRET_KEY], $_SESSION['_pickup_mfa_error']);
        $_SESSION[self::MFA_PENDING_KEY] = [
            'username' => $principal->username,
            'version' => $principal->authenticationVersion,
            'subject' => $principal->securitySubject(),
            'expires_at' => time() + 300,
            'attempts' => 0,
        ];
    }

    private function pendingMfaPrincipal(): ?RecordsPrincipal
    {
        $pending = $_SESSION[self::MFA_PENDING_KEY] ?? null;
        if (!is_array($pending)
            || !is_string($pending['username'] ?? null)
            || !is_string($pending['version'] ?? null)
            || !is_string($pending['subject'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < time()) {
            return null;
        }
        $principal = $this->recordsAccess->resolvePrincipal($pending['username']);
        if ($principal === null
            || $principal->identityProvider !== 'local'
            || !hash_equals($pending['version'], $principal->authenticationVersion)
            || !hash_equals($pending['subject'], $principal->securitySubject())) {
            return null;
        }
        return $principal;
    }

    private function failedMfaAttempt(
        Request $request,
        RecordsPrincipal $principal,
        string $message = 'The authenticator or recovery code is invalid.',
    ): Response
    {
        $pending = $_SESSION[self::MFA_PENDING_KEY] ?? [];
        $attempts = is_array($pending) && is_int($pending['attempts'] ?? null) ? $pending['attempts'] + 1 : 1;
        $this->securityLogger->event('pickupsheet.local_mfa', $request, 'denied', [
            'actor_id' => substr(hash('sha256', $principal->username), 0, 24),
            'attempt' => $attempts,
        ]);
        if ($attempts >= 5) {
            $this->clearPendingMfa();
            $_SESSION['_pickup_login_error'] = 'Too many incorrect verification codes. Sign in again.';
            return Response::redirect($request->basePath . '/dhl/pickupsheet/login');
        }
        $_SESSION[self::MFA_PENDING_KEY]['attempts'] = $attempts;
        $_SESSION['_pickup_mfa_error'] = $message;
        return Response::redirect($request->basePath . '/dhl/pickupsheet/login/2fa');
    }

    private function clearPendingMfa(): void
    {
        unset($_SESSION[self::MFA_PENDING_KEY], $_SESSION[self::MFA_SETUP_SECRET_KEY], $_SESSION['_pickup_mfa_error']);
    }

    private function mfaSettingsWriteGuard(Request $request, RecordsPrincipal $principal): ?Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->securityLogger->event('pickupsheet.user_mfa_csrf', $request, 'denied', [
                'actor_id' => $this->actorId($principal),
            ]);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        try {
            $retryAfter = $this->rateLimiter->consume(
                'pickup-user-mfa-settings',
                $request->clientIdentifier() . ':' . substr(hash('sha256', $principal->securitySubject()), 0, 16),
                10,
                900,
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Two-factor settings are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            return Response::html('Too many two-factor settings attempts. Please try again later.', 429, array_merge(
                $this->privateHeaders(),
                ['Retry-After' => (string) $retryAfter],
            ));
        }
        return null;
    }

    private function reauthenticates(RecordsPrincipal $principal, string $password): bool
    {
        $authenticated = $this->recordsAccess->authenticateCredentials($principal->username, $password);
        return $authenticated !== null
            && $authenticated->identityProvider === 'local'
            && hash_equals($principal->securitySubject(), $authenticated->securitySubject())
            && hash_equals($principal->authenticationVersion, $authenticated->authenticationVersion);
    }

    private function actorId(RecordsPrincipal $principal): string
    {
        return substr(hash('sha256', $principal->username), 0, 24);
    }

    private function settingsMfaReplacementAuthorized(RecordsPrincipal $principal): bool
    {
        $replacement = $_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY] ?? null;
        $authorized = is_array($replacement)
            && is_string($replacement['subject'] ?? null)
            && is_int($replacement['expires_at'] ?? null)
            && $replacement['expires_at'] >= time()
            && hash_equals($principal->securitySubject(), $replacement['subject']);
        if (!$authorized) {
            unset($_SESSION[self::SETTINGS_MFA_REPLACEMENT_KEY]);
        }
        return $authorized;
    }

    private function otpauthUri(string $username, string $secret): string
    {
        $issuer = trim((string) (getenv('PICKUPSHEET_MFA_ISSUER') ?: 'T&Tech Pickupsheet'));
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer . ':' . strtolower($username)),
            $secret,
            rawurlencode($issuer),
        );
    }

}
