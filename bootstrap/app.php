<?php

declare(strict_types=1);

use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Modules\Contact\Infrastructure\MysqlInquiryRepository;
use App\Modules\Contact\UI\ContactController;
use App\Modules\Pickupsheet\UI\PickupsheetController;
use App\Modules\Site\UI\SiteController;
use App\Shared\Http\Application;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\Router;
use App\Shared\Infrastructure\Database;
use App\Shared\Infrastructure\Environment;
use App\Shared\Infrastructure\MigrationRunner;
use App\Shared\Security\Csrf;
use App\Shared\View\View;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
Environment::load($root . '/.env');
$config = require $root . '/config/app.php';
date_default_timezone_set((string) $config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($root . '/storage/sessions');
    session_name('ttechcg_session');
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
$inquiryRepository = $connection === null
    ? new DemoInquiryRepository()
    : new MysqlInquiryRepository($connection);
$storageMode = $connection === null ? 'Demo workspace' : 'MySQL connected';

$view = new View($root . '/views');
$csrf = new Csrf();
$siteController = new SiteController($view, $config, $storageMode);
$contactController = new ContactController(new InquiryService($inquiryRepository), $view, $csrf, $config, $storageMode);
$pickupsheetController = new PickupsheetController($view, $config, $storageMode);

$router = new Router();
$router->get('/', fn (Request $request): Response => $siteController->home($request));
$router->get('/services', fn (Request $request): Response => $siteController->services($request));
$router->get('/about', fn (Request $request): Response => $siteController->about($request));
$router->get('/contact', fn (Request $request): Response => $contactController->index($request));
$router->post('/contact', fn (Request $request): Response => $contactController->store($request));
$router->get('/pickupsheet', fn (Request $request): Response => $pickupsheetController->show($request));
$router->get('/health', fn (Request $request): Response => $siteController->health($request));
$router->fallback(fn (Request $request): Response => $siteController->notFound($request));

return new Application($router);
