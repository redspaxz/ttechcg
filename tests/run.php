<?php

declare(strict_types=1);

use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Shared\Http\Request;
use App\Shared\View\View;

require dirname(__DIR__) . '/bootstrap/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(dirname(__DIR__) . '/storage/sessions');
    session_start();
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$_SESSION = [];
$service = new InquiryService(new DemoInquiryRepository());
$inquiry = $service->submit([
    'name' => 'Test Operator',
    'email' => 'OPERATOR@EXAMPLE.COM',
    'company' => 'Test Company',
    'service' => 'workflow-automation',
    'message' => 'We need a clearer operational workflow for our field team.',
]);
$assert($inquiry->id === 1, 'Inquiry should receive a demo ID.');
$assert($inquiry->email === 'operator@example.com', 'Email should be normalized.');

$validationFailed = false;
try {
    $service->submit(['name' => 'A', 'email' => 'invalid', 'service' => '', 'message' => 'short']);
} catch (InvalidArgumentException) {
    $validationFailed = true;
}
$assert($validationFailed, 'Invalid inquiries should be rejected.');

$request = new Request('GET', '/services', [], [], '');
$assert($request->path === '/services', 'Request should retain the routed path.');

$config = require dirname(__DIR__) . '/config/app.php';
$view = new View(dirname(__DIR__) . '/views');
$common = [
    'basePath' => '',
    'assetBase' => '/public/assets',
    'config' => $config,
    'storageMode' => 'Demo workspace',
];
$home = $view->render('site/home', array_merge($common, [
    'pageTitle' => 'Technology that moves the work forward',
    'pageDescription' => 'Test description',
    'activePage' => 'home',
]));
$assert(str_contains($home, 'T&amp;Tech'), 'Corporate brand should render.');
$assert(str_contains($home, 'Digital product engineering'), 'Services should render on the home page.');
$assert(str_contains($home, 'dhl-logo.svg'), 'Pickupsheet should render as a dedicated product section.');
$assert(str_contains($home, 'href="/pickupsheet"'), 'Pickupsheet should have a root-domain route.');

$product = $view->render('pickupsheet/show', array_merge($common, [
    'pageTitle' => 'Pickupsheet logistics operations',
    'pageDescription' => 'Test description',
    'activePage' => 'pickupsheet',
]));
$assert(str_contains($product, 'pickupsheet'), 'The Pickupsheet product page should render.');
$assert(str_contains($product, 'One clear view'), 'The Pickupsheet value proposition should render.');

$styles = file_get_contents(dirname(__DIR__) . '/public/assets/styles.css');
$assert(is_string($styles) && str_contains($styles, '--navy: #001d39;'), 'T&Tech navy should be the corporate foundation.');
$assert(is_string($styles) && str_contains($styles, '--dhl-yellow: #ffcc00;'), 'Pickupsheet should retain the DHL-yellow treatment.');

$database = file_get_contents(dirname(__DIR__) . '/src/Shared/Infrastructure/Database.php');
$assert(is_string($database) && str_contains($database, "extension_loaded('pdo_mysql')"), 'The application should use PDO MySQL.');

echo "All application tests passed.\n";

