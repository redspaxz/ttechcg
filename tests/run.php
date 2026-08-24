<?php

declare(strict_types=1);

use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryNotifier;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Modules\Contact\Infrastructure\UnavailableInquiryRepository;
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

$notifier = new class implements InquiryNotifier {
    public bool $called = false;

    public function notify(Inquiry $inquiry): bool
    {
        $this->called = true;
        return true;
    }
};
$notifyingService = new InquiryService(new DemoInquiryRepository(), $notifier);
$notifyingService->submit([
    'name' => 'Notification Test',
    'email' => 'notify@example.com',
    'company' => '',
    'service' => 'technical-advisory',
    'message' => 'This inquiry verifies the configured notification workflow.',
]);
$assert($notifier->called, 'A persisted inquiry should trigger its notifier.');

$storageFailed = false;
try {
    (new InquiryService(new UnavailableInquiryRepository()))->submit([
        'name' => 'Storage Test',
        'email' => 'storage@example.com',
        'company' => '',
        'service' => 'other',
        'message' => 'This inquiry should fail instead of being stored in a session.',
    ]);
} catch (RuntimeException) {
    $storageFailed = true;
}
$assert($storageFailed, 'Unavailable production storage should fail explicitly.');

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
$assert(str_contains($home, 'styles.css?v=20260824-minimal'), 'The minimal design stylesheet should use a cache-safe version.');

$product = $view->render('pickupsheet/show', array_merge($common, [
    'pageTitle' => 'Pickupsheet logistics operations',
    'pageDescription' => 'Test description',
    'activePage' => 'pickupsheet',
]));
$assert(str_contains($product, 'pickupsheet'), 'The Pickupsheet product page should render.');
$assert(str_contains($product, 'One clear view'), 'The Pickupsheet value proposition should render.');

$privacy = $view->render('site/privacy', array_merge($common, [
    'pageTitle' => 'Privacy notice',
    'pageDescription' => 'Test description',
    'activePage' => 'privacy',
]));
$assert(str_contains($privacy, 'Information we collect'), 'The privacy notice should explain collected information.');
$assert(str_contains($privacy, 'We do not sell inquiry information.'), 'The privacy notice should state the use limitation.');

$styles = file_get_contents(dirname(__DIR__) . '/public/assets/styles.css');
$assert(is_string($styles) && str_contains($styles, '--navy: #0b0b0c;'), 'T&Tech near-black should be the minimal corporate foundation.');
$assert(is_string($styles) && str_contains($styles, '--copper: #d40511;'), 'T&Tech red should be the corporate accent.');
$assert(is_string($styles) && str_contains($styles, '--paper: #ffffff;'), 'T&Tech white should be the corporate canvas.');
$assert(is_string($styles) && str_contains($styles, '--dhl-yellow: #ffcc00;'), 'Pickupsheet should retain the DHL-yellow treatment.');
$assert(is_string($styles) && str_contains($styles, '--container: 1180px;'), 'The minimal layout should use the restrained content width.');
$assert(is_string($styles) && str_contains($styles, '.signal-orbit { display: none; }'), 'Decorative hero orbits should remain removed.');

$database = file_get_contents(dirname(__DIR__) . '/src/Shared/Infrastructure/Database.php');
$assert(is_string($database) && str_contains($database, "extension_loaded('pdo_mysql')"), 'The application should use PDO MySQL.');

echo "All application tests passed.\n";
