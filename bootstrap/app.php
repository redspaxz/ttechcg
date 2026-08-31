<?php

declare(strict_types=1);

use App\Modules\Backup\Application\BackupService;
use App\Modules\Backup\Infrastructure\MysqlBackupRepository;
use App\Modules\Backup\Infrastructure\UnavailableBackupRepository;
use App\Modules\Backup\UI\BackupController;
use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Modules\Contact\Infrastructure\MysqlInquiryRepository;
use App\Modules\Contact\Infrastructure\NativeMailInquiryNotifier;
use App\Modules\Contact\Infrastructure\UnavailableInquiryRepository;
use App\Modules\Contact\UI\ContactController;
use App\Modules\CRM\Application\CustomerService;
use App\Modules\CRM\Infrastructure\DemoCustomerRepository;
use App\Modules\CRM\Infrastructure\MysqlCustomerRepository;
use App\Modules\CRM\Infrastructure\UnavailableCustomerRepository;
use App\Modules\CRM\UI\CustomerController;
use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Infrastructure\DemoPickupSheetRepository;
use App\Modules\Pickupsheet\Infrastructure\MysqlPickupSheetRepository;
use App\Modules\Pickupsheet\Infrastructure\UnavailablePickupSheetRepository;
use App\Modules\Pickupsheet\UI\PickupsheetAuthController;
use App\Modules\Pickupsheet\UI\PickupsheetController;
use App\Modules\Site\UI\SiteController;
use App\Shared\Http\Application;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\Router;
use App\Shared\Infrastructure\Database;
use App\Shared\Infrastructure\DemoRecordsUserRepository;
use App\Shared\Infrastructure\DemoRecordsSessionActivityRepository;
use App\Shared\Infrastructure\DemoSecurityEventRepository;
use App\Shared\Infrastructure\Environment;
use App\Shared\Infrastructure\MigrationRunner;
use App\Shared\Infrastructure\DemoLoginMethodSettingsRepository;
use App\Shared\Infrastructure\DemoLocalMfaRepository;
use App\Shared\Infrastructure\MysqlLoginMethodSettingsRepository;
use App\Shared\Infrastructure\MysqlLocalMfaRepository;
use App\Shared\Infrastructure\MysqlRecordsUserRepository;
use App\Shared\Infrastructure\MysqlRecordsSessionActivityRepository;
use App\Shared\Infrastructure\MysqlSecurityEventRepository;
use App\Shared\Infrastructure\SecurityDataRetention;
use App\Shared\Infrastructure\UnavailableRecordsUserRepository;
use App\Shared\Infrastructure\UnavailableLoginMethodSettingsRepository;
use App\Shared\Infrastructure\UnavailableLocalMfaRepository;
use App\Shared\Infrastructure\UnavailableRecordsSessionActivityRepository;
use App\Shared\Infrastructure\UnavailableSecurityEventRepository;
use App\Shared\Security\Captcha;
use App\Shared\Security\CloudflareAccessProvider;
use App\Shared\Security\Csrf;
use App\Shared\Security\JumpCloudOidcProvider;
use App\Shared\Security\LoginMethodSettingsService;
use App\Shared\Security\LocalMfaService;
use App\Shared\Security\PickupsheetCountryPolicy;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\RecordsUserService;
use App\Shared\Security\SecurityHeaders;
use App\Shared\Security\SecurityLogger;
use App\Shared\Security\UnsafeRequestPolicy;
use App\Shared\View\View;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
Environment::load($root . '/.env');
$config = require $root . '/config/app.php';
date_default_timezone_set((string) $config['timezone']);
$isProduction = $config['environment'] === 'production';
$applicationUrlParts = parse_url((string) ($config['app_url'] ?? ''));
if ($isProduction && (!is_array($applicationUrlParts)
    || ($applicationUrlParts['scheme'] ?? null) !== 'https'
    || !is_string($applicationUrlParts['host'] ?? null)
    || strtolower(rtrim($applicationUrlParts['host'], '.')) !== 'ttechcg.com'
    || !in_array($applicationUrlParts['path'] ?? '', ['', '/'], true)
    || isset($applicationUrlParts['port'])
    || isset($applicationUrlParts['user'])
    || isset($applicationUrlParts['pass'])
    || isset($applicationUrlParts['query'])
    || isset($applicationUrlParts['fragment']))) {
    error_log('Invalid production APP_URL was replaced by the canonical HTTPS origin.');
    $config['app_url'] = 'https://ttechcg.com';
}

error_reporting(E_ALL);
ini_set('display_errors', !$isProduction && ($config['debug'] ?? false) ? '1' : '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');
ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');
header_remove('X-Powered-By');
if ($isProduction && ($config['debug'] ?? false)) {
    error_log('Production APP_DEBUG was ignored by the application security boundary.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', '28800');
    session_save_path($root . '/storage/sessions');
    session_name($isProduction ? '__Host-ttechcg_session' : 'ttechcg_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isProduction || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$connection = Database::connect();
$runMigrations = filter_var(getenv('RUN_MIGRATIONS') ?: !$isProduction, FILTER_VALIDATE_BOOL);
if ($connection !== null && $runMigrations) {
    try {
        MigrationRunner::run($connection, $root . '/database/migrations');
    } catch (Throwable $exception) {
        error_log($exception->__toString());
        $connection = null;
    }
}
if ($connection !== null) {
    try {
        $retention = new SecurityDataRetention(
            $connection,
            $root . '/storage/security',
            (int) ($config['security_event_retention_days'] ?? 365),
            (int) ($config['session_activity_retention_days'] ?? 365),
        );
        $retentionResult = $retention->runIfDue();
        if ($retentionResult !== null) {
            error_log((string) json_encode([
                'type' => 'security_retention',
                'events_deleted' => $retentionResult['events'],
                'sessions_deleted' => $retentionResult['sessions'],
                'occurred_at' => gmdate(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES));
        }
    } catch (Throwable $exception) {
        error_log('Security data retention could not run: ' . $exception->getMessage());
    }
}
$inquiryRepository = match (true) {
    $connection !== null => new MysqlInquiryRepository($connection),
    $isProduction => new UnavailableInquiryRepository(),
    default => new DemoInquiryRepository(),
};
$pickupSheetRepository = match (true) {
    $connection !== null => new MysqlPickupSheetRepository($connection),
    $isProduction => new UnavailablePickupSheetRepository(),
    default => new DemoPickupSheetRepository(),
};
$customerRepository = match (true) {
    $connection !== null => new MysqlCustomerRepository($connection),
    $isProduction => new UnavailableCustomerRepository(),
    default => new DemoCustomerRepository($pickupSheetRepository),
};
$recordsUserRepository = match (true) {
    $connection !== null => new MysqlRecordsUserRepository($connection),
    $isProduction => new UnavailableRecordsUserRepository(),
    default => new DemoRecordsUserRepository(),
};
$recordsSessionActivityRepository = match (true) {
    $connection !== null => new MysqlRecordsSessionActivityRepository($connection),
    $isProduction => new UnavailableRecordsSessionActivityRepository(),
    default => new DemoRecordsSessionActivityRepository(),
};
$securityEventRepository = match (true) {
    $connection !== null => new MysqlSecurityEventRepository($connection),
    $isProduction => new UnavailableSecurityEventRepository(),
    default => new DemoSecurityEventRepository(),
};
$loginMethodSettingsRepository = match (true) {
    $connection !== null => new MysqlLoginMethodSettingsRepository($connection),
    $isProduction => new UnavailableLoginMethodSettingsRepository(),
    default => new DemoLoginMethodSettingsRepository(),
};
$localMfaRepository = match (true) {
    $connection !== null => new MysqlLocalMfaRepository($connection),
    $isProduction => new UnavailableLocalMfaRepository(),
    default => new DemoLocalMfaRepository(),
};
$backupRepository = $connection !== null
    ? new MysqlBackupRepository($connection)
    : new UnavailableBackupRepository();
$storageMode = $connection === null ? 'Demo workspace' : 'MySQL connected';
$contactEmail = (string) ($config['contact_email'] ?? '');
$notifier = filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
    ? new NativeMailInquiryNotifier($contactEmail, (string) $config['contact_from_email'])
    : null;
$contactOperational = !$isProduction || ($connection !== null && $notifier !== null);
$pickupOperational = !$isProduction || $connection !== null;

$view = new View($root . '/views');
$csrf = new Csrf();
$rateLimiter = new RateLimiter($root . '/storage/security');
$securityLogger = new SecurityLogger(true, $securityEventRepository);
$recordsAccess = RecordsAccess::fromEnvironment($recordsUserRepository);
$recordsSession = new RecordsSession($recordsSessionActivityRepository);
$jumpCloud = JumpCloudOidcProvider::fromEnvironment();
$cloudflareAccess = CloudflareAccessProvider::fromEnvironment();
$config['jumpcloud_oidc_configured'] = $jumpCloud->isConfigured();
$config['jumpcloud_login_enabled'] = $jumpCloud->isConfigured();
$config['jumpcloud_role_groups'] = $jumpCloud->roleGroups();
$config['cloudflare_access_configured'] = $cloudflareAccess->isConfigured();
$localMfa = LocalMfaService::fromEnvironment($localMfaRepository);
$config['local_mfa_enabled'] = $localMfa->isEnabled();
$config['local_mfa_configured'] = $localMfa->isConfigured();
$localLoginSecurityReady = (bool) ($config['local_login_enabled'] ?? true)
    && (!$isProduction || ($localMfa->isEnabled() && $localMfa->isConfigured()));
$config['local_login_enabled'] = $localLoginSecurityReady;
$config['local_login_security_ready'] = $localLoginSecurityReady;
$loginMethodSettings = new LoginMethodSettingsService(
    $loginMethodSettingsRepository,
    $localLoginSecurityReady,
    $jumpCloud->isConfigured(),
    $cloudflareAccess->isConfigured(),
);
$recordsUserService = new RecordsUserService($recordsUserRepository, $recordsAccess->environmentUsernames());
$contactCaptcha = new Captcha('contact');
$pickupCaptcha = new Captcha('pickupsheet');
$siteController = new SiteController($view, $config, $storageMode, $contactOperational);
$contactController = new ContactController(
    new InquiryService($inquiryRepository, $notifier),
    $view,
    $csrf,
    $contactCaptcha,
    $config,
    $storageMode,
    $contactOperational,
    $rateLimiter,
    $securityLogger,
);
$pickupsheetAuthController = new PickupsheetAuthController(
    $view,
    $csrf,
    $recordsAccess,
    $recordsSession,
    $rateLimiter,
    $securityLogger,
    $config,
    $jumpCloud,
    $cloudflareAccess,
    $loginMethodSettings,
    $localMfa,
);
$pickupsheetController = new PickupsheetController(
    new PickupSheetService($pickupSheetRepository),
    $view,
    $csrf,
    $pickupCaptcha,
    $config,
    $storageMode,
    $pickupOperational,
    $recordsAccess,
    $recordsSession,
    $recordsUserService,
    $rateLimiter,
    $securityLogger,
    $loginMethodSettings,
    $localMfa,
);
$customerController = new CustomerController(
    new CustomerService($customerRepository),
    $view,
    $csrf,
    $recordsAccess,
    $recordsSession,
    $rateLimiter,
    $securityLogger,
);
$backupController = new BackupController(
    new BackupService($backupRepository),
    $view,
    $csrf,
    $recordsAccess,
    $recordsSession,
    $rateLimiter,
    $securityLogger,
    $connection !== null && extension_loaded('openssl') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
);

$router = new Router();
$router->get('/', fn (Request $request): Response => $siteController->home($request));
$router->get('/services', fn (Request $request): Response => $siteController->services($request));
$router->get('/products', fn (Request $request): Response => $siteController->products($request));
$router->get('/about', fn (Request $request): Response => $siteController->about($request));
$router->get('/contact', fn (Request $request): Response => $contactController->index($request));
$router->post('/contact', fn (Request $request): Response => $contactController->store($request));
$router->get('/dhl/pickupsheet/login', fn (Request $request): Response => $pickupsheetAuthController->login($request));
$router->post('/dhl/pickupsheet/login', fn (Request $request): Response => $pickupsheetAuthController->authenticate($request));
$router->get('/dhl/pickupsheet/login/2fa', fn (Request $request): Response => $pickupsheetAuthController->mfa($request));
$router->post('/dhl/pickupsheet/login/2fa', fn (Request $request): Response => $pickupsheetAuthController->verifyMfa($request));
$router->get('/dhl/pickupsheet/login/2fa/recovery-codes', fn (Request $request): Response => $pickupsheetAuthController->recoveryCodes($request));
$router->get('/dhl/pickupsheet/settings', fn (Request $request): Response => $pickupsheetAuthController->settings($request));
$router->post('/dhl/pickupsheet/settings/2fa/enroll', fn (Request $request): Response => $pickupsheetAuthController->enrollSettingsMfa($request));
$router->post('/dhl/pickupsheet/settings/2fa/reset', fn (Request $request): Response => $pickupsheetAuthController->resetSettingsMfa($request));
$router->get('/dhl/pickupsheet/settings/2fa/recovery-codes', fn (Request $request): Response => $pickupsheetAuthController->settingsRecoveryCodes($request));
$router->get('/dhl/pickupsheet/auth/jumpcloud', fn (Request $request): Response => $pickupsheetAuthController->jumpCloudStart($request));
$router->get('/dhl/pickupsheet/auth/jumpcloud/callback', fn (Request $request): Response => $pickupsheetAuthController->jumpCloudCallback($request));
$router->post('/dhl/pickupsheet/logout', fn (Request $request): Response => $pickupsheetAuthController->logout($request));
$router->get('/dhl/pickupsheet/dashboard', fn (Request $request): Response => $pickupsheetController->dashboard($request));
$router->get('/dhl/pickupsheet/dashboard/user-activity/page', fn (Request $request): Response => $pickupsheetController->dashboardUserActivityPage($request));
$router->get('/dhl/pickupsheet/dashboard/audit-logs/page', fn (Request $request): Response => $pickupsheetController->dashboardAuditLogPage($request));
$router->get('/dhl/pickupsheet/dashboard/recent-sheets/page', fn (Request $request): Response => $pickupsheetController->dashboardRecentSheetsPage($request));
$router->get('/dhl/pickupsheet/admin/backup', fn (Request $request): Response => $backupController->index($request));
$router->post('/dhl/pickupsheet/admin/backup/download', fn (Request $request): Response => $backupController->download($request));
$router->post('/dhl/pickupsheet/admin/backup/restore', fn (Request $request): Response => $backupController->restore($request));
$router->get('/dhl/pickupsheet/customers', fn (Request $request): Response => $customerController->index($request));
$router->get('/dhl/pickupsheet/customers/page', fn (Request $request): Response => $customerController->page($request));
$router->get('/dhl/pickupsheet/customers/new', fn (Request $request): Response => $customerController->create($request));
$router->get('/dhl/pickupsheet/customers/edit', fn (Request $request): Response => $customerController->edit($request));
$router->get('/dhl/pickupsheet/customers/shipments/page', fn (Request $request): Response => $customerController->shipmentPage($request));
$router->get('/dhl/pickupsheet/customers/redemptions/page', fn (Request $request): Response => $customerController->redemptionPage($request));
$router->post('/dhl/pickupsheet/customers/save', fn (Request $request): Response => $customerController->save($request));
$router->post('/dhl/pickupsheet/customers/rewards', fn (Request $request): Response => $customerController->adjustRewards($request));
$router->get('/dhl/pickupsheet', fn (Request $request): Response => $pickupsheetController->index($request));
$router->get('/dhl/pickupsheet/consignors/search', fn (Request $request): Response => $pickupsheetController->searchConsignors($request));
$router->post('/dhl/pickupsheet', fn (Request $request): Response => $pickupsheetController->store($request));
$router->get('/dhl/pickupsheet/submissions', fn (Request $request): Response => $pickupsheetController->submissions($request));
$router->get('/dhl/pickupsheet/submissions/page', fn (Request $request): Response => $pickupsheetController->submissionsPage($request));
$router->get('/dhl/pickupsheet/submissions/edit', fn (Request $request): Response => $pickupsheetController->edit($request));
$router->post('/dhl/pickupsheet/submissions/edit', fn (Request $request): Response => $pickupsheetController->updatePickupSheet($request));
$router->post('/dhl/pickupsheet/submissions/paid', fn (Request $request): Response => $pickupsheetController->markPickupSheetPaid($request));
$router->post('/dhl/pickupsheet/submissions/delete', fn (Request $request): Response => $pickupsheetController->deletePickupSheet($request));
$router->get('/dhl/pickupsheet/submissions/users', fn (Request $request): Response => $pickupsheetController->users($request));
$router->post('/dhl/pickupsheet/submissions/users', fn (Request $request): Response => $pickupsheetController->createUser($request));
$router->post('/dhl/pickupsheet/submissions/users/update', fn (Request $request): Response => $pickupsheetController->updateUser($request));
$router->post('/dhl/pickupsheet/submissions/users/status', fn (Request $request): Response => $pickupsheetController->setUserStatus($request));
$router->post('/dhl/pickupsheet/submissions/users/delete', fn (Request $request): Response => $pickupsheetController->deleteUser($request));
$router->get('/dhl/pickupsheet/submissions/users/mfa/reset', fn (Request $request): Response => $pickupsheetController->confirmUserMfaReset($request));
$router->post('/dhl/pickupsheet/submissions/users/mfa/reset', fn (Request $request): Response => $pickupsheetController->resetUserMfa($request));
$router->post('/dhl/pickupsheet/submissions/users/login-methods', fn (Request $request): Response => $pickupsheetController->updateLoginMethods($request));
$router->post('/dhl/pickupsheet/submissions/users/admin-password', fn (Request $request): Response => $pickupsheetController->resetAdminPassword($request));
$router->get('/dhl/pickupsheet/submissions/print', fn (Request $request): Response => $pickupsheetController->print($request));
$router->get('/dhl/pickupsheet/submissions/export', fn (Request $request): Response => $pickupsheetController->export($request));
$router->get('/pickupsheet', fn (Request $request): Response => Response::redirect($request->basePath . '/dhl/pickupsheet/', 308));
$router->post('/pickupsheet', fn (Request $request): Response => Response::redirect($request->basePath . '/dhl/pickupsheet', 308));
$router->get('/pickupsheet/submissions', fn (Request $request): Response => Response::redirect($request->basePath . '/dhl/pickupsheet/submissions', 308));
$router->get('/pickupsheet/submissions/print', static function (Request $request): Response {
    $reference = $request->queryString('reference');
    $location = $request->basePath . '/dhl/pickupsheet/submissions/print';
    return Response::redirect($location . ($reference === '' ? '' : '?reference=' . rawurlencode($reference)), 308);
});
$router->get('/pickupsheet/submissions/export', static function (Request $request): Response {
    $reference = $request->queryString('reference');
    $location = $request->basePath . '/dhl/pickupsheet/submissions/export';
    return Response::redirect($location . ($reference === '' ? '' : '?reference=' . rawurlencode($reference)), 308);
});
$router->get('/privacy', fn (Request $request): Response => $siteController->privacy($request));
$router->get('/health', fn (Request $request): Response => $siteController->health($request));
$router->fallback(fn (Request $request): Response => $siteController->notFound($request));

return new Application(
    $router,
    PickupsheetCountryPolicy::fromEnvironment($isProduction),
    $securityLogger,
    new UnsafeRequestPolicy((string) $config['app_url']),
    new SecurityHeaders($isProduction),
);
