<?php

declare(strict_types=1);

use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Modules\Contact\Infrastructure\MysqlInquiryRepository;
use App\Modules\Contact\Infrastructure\NativeMailInquiryNotifier;
use App\Modules\Contact\Infrastructure\UnavailableInquiryRepository;
use App\Modules\Contact\UI\ContactController;
use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Infrastructure\DemoPickupSheetRepository;
use App\Modules\Pickupsheet\Infrastructure\MysqlPickupSheetRepository;
use App\Modules\Pickupsheet\Infrastructure\UnavailablePickupSheetRepository;
use App\Modules\Pickupsheet\UI\PickupsheetController;
use App\Modules\Site\UI\SiteController;
use App\Shared\Http\Application;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\Router;
use App\Shared\Infrastructure\Database;
use App\Shared\Infrastructure\Environment;
use App\Shared\Infrastructure\MigrationRunner;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
use App\Shared\Security\JumpCloudOidcProvider;
use App\Shared\View\View;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
Environment::load($root . '/.env');
$config = require $root . '/config/app.php';
date_default_timezone_set((string) $config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($root . '/storage/sessions');
    session_name('ttechcg_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$connection = Database::connect();
if ($connection !== null) {
    try {
        MigrationRunner::run($connection, $root . '/database/migrations');
    } catch (Throwable $exception) {
        error_log($exception->__toString());
        $connection = null;
    }
}
$isProduction = $config['environment'] === 'production';
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
$storageMode = $connection === null ? 'Demo workspace' : 'MySQL connected';
$contactEmail = (string) ($config['contact_email'] ?? '');
$notifier = filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
    ? new NativeMailInquiryNotifier($contactEmail, (string) $config['contact_from_email'])
    : null;
$contactOperational = !$isProduction || ($connection !== null && $notifier !== null);
$pickupOperational = !$isProduction || $connection !== null;

$view = new View($root . '/views');
$csrf = new Csrf();
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
);
$pickupsheetController = new PickupsheetController(
    new PickupSheetService($pickupSheetRepository),
    $view,
    $csrf,
    $pickupCaptcha,
    new JumpCloudOidcProvider($config),
    $config,
    $storageMode,
    $pickupOperational,
);

$router = new Router();
$router->get('/', fn (Request $request): Response => $siteController->home($request));
$router->get('/services', fn (Request $request): Response => $siteController->services($request));
$router->get('/products', fn (Request $request): Response => $siteController->products($request));
$router->get('/about', fn (Request $request): Response => $siteController->about($request));
$router->get('/contact', fn (Request $request): Response => $contactController->index($request));
$router->post('/contact', fn (Request $request): Response => $contactController->store($request));
$router->get('/pickupsheet', fn (Request $request): Response => $pickupsheetController->index($request));
$router->post('/pickupsheet', fn (Request $request): Response => $pickupsheetController->store($request));
$router->post('/pickupsheet/login', fn (Request $request): Response => $pickupsheetController->login($request));
$router->get('/pickupsheet/login/callback', fn (Request $request): Response => $pickupsheetController->loginCallback($request));
$router->post('/pickupsheet/logout', fn (Request $request): Response => $pickupsheetController->logout($request));
$router->get('/pickupsheet/submissions', fn (Request $request): Response => $pickupsheetController->submissions($request));
$router->get('/pickupsheet/submissions/print', fn (Request $request): Response => $pickupsheetController->print($request));
$router->get('/pickupsheet/submissions/export', fn (Request $request): Response => $pickupsheetController->export($request));
$router->get('/privacy', fn (Request $request): Response => $siteController->privacy($request));
$router->get('/health', fn (Request $request): Response => $siteController->health($request));
$router->fallback(fn (Request $request): Response => $siteController->notFound($request));

return new Application($router);
