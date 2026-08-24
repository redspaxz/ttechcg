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
    'service' => 'network-outsourcing',
    'message' => 'We need reliable network operations across our field locations.',
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
    'service' => 'btspos',
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

$request = new Request('GET', '/products', [], [], '');
$assert($request->path === '/products', 'Request should retain the routed path.');

$config = require dirname(__DIR__) . '/config/app.php';
$view = new View(dirname(__DIR__) . '/views');
$common = [
    'basePath' => '',
    'assetBase' => '/public/assets',
    'config' => $config,
    'storageMode' => 'Demo workspace',
];
$home = $view->render('site/home', array_merge($common, [
    'pageTitle' => 'Network outsourcing and managed solutions',
    'pageDescription' => 'Test description',
    'activePage' => 'home',
]));
$assert(str_contains($home, 'T&amp;Tech'), 'Corporate brand should render.');
$assert(str_contains($home, 'Network outsourcing'), 'Network outsourcing should lead the home page positioning.');
$assert(str_contains($home, 'href="/products"'), 'The public product catalogue should be linked in the site navigation.');
$assert(str_contains($home, 'Amazon Web Services'), 'The AWS partnership should be presented on the home page.');
$assert(str_contains($home, 'Microsoft'), 'The Microsoft partnership should be presented on the home page.');
$assert(str_contains($home, 'IBM'), 'The IBM partnership should be presented on the home page.');
$assert(str_contains($home, 'Red Hat'), 'The Red Hat partnership should be presented on the home page.');
$assert(!str_contains($home, 'href="/pickupsheet"'), 'Pickupsheet should not be discoverable from the public site chrome or homepage.');
$assert(!str_contains($home, 'dhl-logo.svg'), 'The private Pickupsheet product should not be promoted on the homepage.');
$assert(str_contains($home, 'styles.css?v=20260824-technology-partners'), 'The technology partner update should use a cache-safe stylesheet version.');
$assert(str_contains($home, '<span class="company-name">T&amp;Tech Consulting Group</span>'), 'The header should render the full company name as text.');
$headerStart = strpos($home, '<header');
$headerEnd = strpos($home, '</header>');
$headerMarkup = $headerStart !== false && $headerEnd !== false ? substr($home, $headerStart, $headerEnd - $headerStart) : '';
$assert(!str_contains($headerMarkup, 'ttechcg-mark.svg'), 'The header should not render the logo image.');

$products = $view->render('site/products', array_merge($common, [
    'pageTitle' => 'Technology products for real operations',
    'pageDescription' => 'Test description',
    'activePage' => 'products',
]));
$assert(str_contains($products, 'BTSPOS'), 'BTSPOS should be listed in the public product catalogue.');
$assert(str_contains($products, 'multi-tenant bus ticketing'), 'The BTSPOS operational purpose should be clear.');
$assert(str_contains($products, 'Discuss BTSPOS'), 'BTSPOS should provide a focused inquiry action.');
$assert(!str_contains($products, 'href="/pickupsheet"'), 'The private Pickupsheet route should not be listed in the product catalogue.');

$product = $view->render('pickupsheet/show', array_merge($common, [
    'pageTitle' => 'Pickupsheet logistics operations',
    'pageDescription' => 'Test description',
    'pageRobots' => 'noindex, nofollow',
    'activePage' => 'pickupsheet',
]));
$assert(str_contains($product, 'pickupsheet'), 'The Pickupsheet product page should render.');
$assert(str_contains($product, 'One clear view'), 'The Pickupsheet value proposition should render.');
$assert(str_contains($product, '<meta name="robots" content="noindex, nofollow">'), 'The direct Pickupsheet page should not be indexed.');
$sitemap = file_get_contents(dirname(__DIR__) . '/sitemap.xml');
$assert(is_string($sitemap) && !str_contains($sitemap, '/pickupsheet'), 'The sitemap should not advertise Pickupsheet.');
$assert(is_string($sitemap) && str_contains($sitemap, '/products'), 'The sitemap should advertise the public product catalogue.');

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
$assert(is_string($styles) && str_contains($styles, '--paper: #0b0b0c;'), 'The corporate canvas should use the inverted black background.');
$assert(is_string($styles) && str_contains($styles, '--ink: #f5f5f3;'), 'The inverted interface should use light text.');
$assert(is_string($styles) && str_contains($styles, '.site-logo .company-name { color: var(--copper); }'), 'The text-only company wordmark should use the matching red accent.');
$assert(is_string($styles) && str_contains($styles, '.product-card-heading'), 'The public product catalogue should have a responsive product layout.');

$database = file_get_contents(dirname(__DIR__) . '/src/Shared/Infrastructure/Database.php');
$assert(is_string($database) && str_contains($database, "extension_loaded('pdo_mysql')"), 'The application should use PDO MySQL.');

echo "All application tests passed.\n";
