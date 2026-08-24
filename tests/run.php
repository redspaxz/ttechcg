<?php

declare(strict_types=1);

use App\Modules\Contact\Application\InquiryService;
use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryNotifier;
use App\Modules\Contact\Infrastructure\DemoInquiryRepository;
use App\Modules\Contact\Infrastructure\UnavailableInquiryRepository;
use App\Modules\Contact\UI\ContactController;
use App\Modules\Pickupsheet\Application\PickupSheetService;
use App\Modules\Pickupsheet\Infrastructure\DemoPickupSheetRepository;
use App\Modules\Pickupsheet\Infrastructure\UnavailablePickupSheetRepository;
use App\Modules\Pickupsheet\UI\PickupsheetController;
use App\Shared\Http\Request;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
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
$captchaService = new Captcha();
$captchaChallenge = $captchaService->issue();
$captchaParts = preg_split('/\s+/', $captchaChallenge['question']);
$assert(is_array($captchaParts) && count($captchaParts) === 3, 'CAPTCHA should issue a readable arithmetic challenge.');
$captchaAnswer = $captchaParts[1] === '+'
    ? (string) ((int) $captchaParts[0] + (int) $captchaParts[2])
    : (string) ((int) $captchaParts[0] - (int) $captchaParts[2]);
$assert($captchaService->validate($captchaChallenge['nonce'], $captchaAnswer), 'A correct CAPTCHA response should validate.');
$assert(!$captchaService->validate($captchaChallenge['nonce'], $captchaAnswer), 'A CAPTCHA challenge should not be reusable.');
$wrongChallenge = $captchaService->issue();
$assert(!$captchaService->validate($wrongChallenge['nonce'], '99'), 'An incorrect CAPTCHA response should fail.');

$service = new InquiryService(new DemoInquiryRepository());
$inquiry = $service->submit([
    'name' => 'Test Operator',
    'email' => 'OPERATOR@EXAMPLE.COM',
    'company' => 'Test Company',
    'service' => 'network-outsourcing',
    'message' => 'We need reliable network operations across our field locations.',
    'privacy_consent' => '1',
]);
$assert($inquiry->id === 1, 'Inquiry should receive a demo ID.');
$assert($inquiry->email === 'operator@example.com', 'Email should be normalized.');
$assert($inquiry->privacyNoticeVersion === InquiryService::PRIVACY_NOTICE_VERSION, 'Accepted inquiries should retain the privacy-notice version.');
$assert($inquiry->privacyConsentAt !== '', 'Accepted inquiries should retain the consent timestamp.');

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
    'privacy_consent' => '1',
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
        'privacy_consent' => '1',
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

$consentFailed = false;
try {
    $service->submit([
        'name' => 'Consent Test',
        'email' => 'consent@example.com',
        'company' => '',
        'service' => 'network-outsourcing',
        'message' => 'This otherwise valid inquiry omits the required privacy opt-in.',
        'privacy_consent' => '',
    ]);
} catch (InvalidArgumentException $exception) {
    $consentFailed = str_contains($exception->getMessage(), 'opt in');
}
$assert($consentFailed, 'An inquiry without explicit privacy consent should be rejected server-side.');

$pickupService = new PickupSheetService(new DemoPickupSheetRepository());
$pickupSheet = $pickupService->submit([
    'agent_name' => 'Edmund Ngochi',
    'collection_date' => '2026-07-29',
    'privacy_consent' => '1',
    'shipments' => [
        [
            'consignor' => 'Nekeziah Pius T',
            'awb_number' => '1661272944',
            'destination' => 'dca',
            'amount' => '59,600',
            'pieces' => '1',
            'weight_kg' => '0.5kg',
            'collection_time' => '10:03',
            'checked_by' => 'Elizabeth A',
        ],
        [
            'consignor' => 'Gumia Ngondab',
            'awb_number' => '1589328716',
            'destination' => 'BUD',
            'amount' => '56100',
            'pieces' => '1',
            'weight_kg' => '0.500',
            'collection_time' => '10:20',
            'checked_by' => 'Elizabeth A',
        ],
    ],
]);
$assert($pickupSheet->id === 1, 'A pickup sheet should receive a repository ID.');
$assert($pickupSheet->shipmentCount() === 2, 'All completed shipment rows should be collected.');
$assert($pickupSheet->totalCashReceivedXaf === 115700, 'The XAF total should be recalculated from shipment rows.');
$assert($pickupSheet->shipments[0]->destination === 'DCA', 'Destination codes should be normalized to uppercase.');
$assert($pickupSheet->shipments[0]->weightKg === '0.500', 'Shipment weight should be normalized for MySQL decimals.');
$assert($pickupSheet->privacyConsentAt !== '', 'Pickup-sheet consent should retain a timestamp.');

$pickupConsentFailed = false;
try {
    $pickupService->submit([
        'agent_name' => 'Consent Test',
        'collection_date' => '2026-07-29',
        'privacy_consent' => '',
        'shipments' => [],
    ]);
} catch (InvalidArgumentException $exception) {
    $pickupConsentFailed = str_contains($exception->getMessage(), 'opt in');
}
$assert($pickupConsentFailed, 'Pickup sheets without explicit privacy consent should be rejected.');

$pickupStorageFailed = false;
try {
    (new PickupSheetService(new UnavailablePickupSheetRepository()))->submit([
        'agent_name' => 'Storage Test',
        'collection_date' => '2026-07-29',
        'privacy_consent' => '1',
        'shipments' => [[
            'consignor' => 'Test Client',
            'awb_number' => '1234567890',
            'destination' => 'DLA',
            'amount' => '1000',
            'pieces' => '1',
            'weight_kg' => '0.5',
            'collection_time' => '10:30',
            'checked_by' => 'Test Checker',
        ]],
    ]);
} catch (RuntimeException) {
    $pickupStorageFailed = true;
}
$assert($pickupStorageFailed, 'Unavailable production pickup storage should fail explicitly.');

$request = new Request('GET', '/products', [], [], '');
$assert($request->path === '/products', 'Request should retain the routed path.');
$arrayRequest = new Request('POST', '/pickupsheet', [], ['shipments' => [['awb_number' => '1234567890']]], '');
$assert(($arrayRequest->arrayInput('shipments')[0]['awb_number'] ?? '') === '1234567890', 'Request should expose nested shipment arrays.');

$config = require dirname(__DIR__) . '/config/app.php';
$view = new View(dirname(__DIR__) . '/views');
$common = [
    'basePath' => '',
    'assetBase' => '/public/assets',
    'config' => $config,
    'storageMode' => 'Demo workspace',
];

$_SESSION = [];
$csrfService = new Csrf();
$captchaService = new Captcha();
$controller = new ContactController(
    new InquiryService(new DemoInquiryRepository()),
    $view,
    $csrfService,
    $captchaService,
    $config,
    'Demo workspace',
    true,
);
$controllerChallenge = $captchaService->issue();
$controllerResponse = $controller->store(new Request('POST', '/contact', [], [
    '_token' => $csrfService->token(),
    'captcha_nonce' => $controllerChallenge['nonce'],
    'captcha_answer' => '99',
    'website' => '',
    'name' => 'CAPTCHA Test',
    'email' => 'captcha@example.com',
    'company' => '',
    'service' => 'network-outsourcing',
    'message' => 'This valid inquiry should be rejected by the incorrect CAPTCHA answer.',
    'privacy_consent' => '1',
], ''));
$assert($controllerResponse->status() === 303, 'An incorrect CAPTCHA should return to the contact form.');
$assert(str_contains((string) ($_SESSION['_errors'][0] ?? ''), 'human verification'), 'An incorrect CAPTCHA should produce a useful form error.');

$_SESSION = [];
$pickupCsrf = new Csrf();
$pickupCaptcha = new Captcha('pickupsheet-test');
$pickupController = new PickupsheetController(
    new PickupSheetService(new DemoPickupSheetRepository()),
    $view,
    $pickupCsrf,
    $pickupCaptcha,
    $config,
    'Demo workspace',
    true,
);
$pickupChallenge = $pickupCaptcha->issue();
$pickupParts = preg_split('/\s+/', $pickupChallenge['question']);
$pickupAnswer = is_array($pickupParts) && $pickupParts[1] === '+'
    ? (string) ((int) $pickupParts[0] + (int) $pickupParts[2])
    : (string) ((int) $pickupParts[0] - (int) $pickupParts[2]);
$pickupControllerResponse = $pickupController->store(new Request('POST', '/pickupsheet', [], [
    '_token' => $pickupCsrf->token(),
    'captcha_nonce' => $pickupChallenge['nonce'],
    'captcha_answer' => $pickupAnswer,
    'website' => '',
    'agent_name' => 'Controller Agent',
    'collection_date' => '2026-07-29',
    'privacy_consent' => '1',
    'shipments' => [[
        'consignor' => 'Controller Client',
        'awb_number' => '1234567890',
        'destination' => 'DLA',
        'amount' => '12000',
        'pieces' => '2',
        'weight_kg' => '1.25',
        'collection_time' => '11:40',
        'checked_by' => 'Controller Checker',
    ]],
], ''));
$assert($pickupControllerResponse->status() === 303, 'A valid pickup sheet should redirect after saving.');
$assert(str_contains((string) ($_SESSION['_pickup_flash'] ?? ''), '12,000 XAF'), 'The pickup controller should confirm the server-calculated total.');

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
$assert(str_contains($home, 'DHL'), 'The DHL partnership should be presented on the home page.');
$assert(str_contains($home, '/partners/aws.png'), 'The AWS partner mark should render from a local asset.');
$assert(str_contains($home, '/partners/microsoft.png'), 'The Microsoft partner mark should render from a local asset.');
$assert(str_contains($home, '/partners/ibm.svg'), 'The IBM partner mark should render from a local asset.');
$assert(str_contains($home, '/partners/red-hat.svg'), 'The Red Hat partner mark should render from a local asset.');
$assert(str_contains($home, '/dhl-logo.svg'), 'The DHL partner mark should render from a local asset.');
$partnerAssets = ['aws.png', 'microsoft.png', 'ibm.svg', 'red-hat.svg'];
foreach ($partnerAssets as $partnerAsset) {
    $partnerAssetPath = dirname(__DIR__) . '/public/assets/partners/' . $partnerAsset;
    $assert(is_file($partnerAssetPath) && filesize($partnerAssetPath) > 1000, $partnerAsset . ' should be a non-empty local partner asset.');
}
$dhlAssetPath = dirname(__DIR__) . '/public/assets/dhl-logo.svg';
$assert(is_file($dhlAssetPath) && filesize($dhlAssetPath) > 100, 'The DHL logo should be a non-empty local partner asset.');
$dhlAsset = file_get_contents($dhlAssetPath);
$assert(is_string($dhlAsset) && str_contains($dhlAsset, 'viewBox="0 0 143.5 20"'), 'The DHL mark should use the official DHL-hosted artwork geometry.');
$assert(is_string($dhlAsset) && !str_contains($dhlAsset, '<text'), 'The disqualified font-rendered DHL artwork should not remain.');
$partnerSources = file_get_contents(dirname(__DIR__) . '/public/assets/partners/README.md');
$assert(is_string($partnerSources) && str_contains($partnerSources, 'www.dhl.com/content/dam/dhl/global/core/images/logos/dhl-logo.svg'), 'The official DHL artwork source should be documented.');
$assert(!str_contains($home, 'href="/pickupsheet"'), 'Pickupsheet should not be discoverable from the public site chrome or homepage.');
$assert(str_contains($home, 'styles.css?v=20260824-pickup-form'), 'The pickup form update should use a cache-safe stylesheet version.');
$assert(str_contains($home, 'app.js?v=20260824-pickup-form'), 'The pickup form update should use a cache-safe application script version.');
$assert(str_contains($home, 'analytics.js?v=20260824-analytics-consent'), 'The consent-aware Google Analytics loader should render on every page.');
$assert(str_contains($home, 'data-analytics-accept'), 'The site should offer an explicit analytics acceptance control.');
$assert(str_contains($home, 'data-analytics-decline'), 'The site should offer an explicit analytics decline control.');
$assert(str_contains($home, 'data-analytics-settings'), 'Visitors should be able to reopen analytics settings from the footer.');
$assert(!str_contains($home, '<script async src="https://www.googletagmanager.com'), 'The remote Google tag should not load in HTML before consent.');
$assert(str_contains($home, 'viewport-fit=cover'), 'The viewport should support mobile safe areas.');
$assert(str_contains($home, 'loading="lazy" decoding="async"'), 'Below-the-fold partner logos should load efficiently on mobile.');
$assert(str_contains($home, '/images/hero-data-center.jpg'), 'The supplied data-center photograph should render in the landing hero.');
$assert(str_contains($home, 'width="1920" height="1047" fetchpriority="high"'), 'The hero image should reserve its source dimensions and receive high loading priority.');
$assert(str_contains($home, 'class="hero-media"'), 'The landing page should render a dedicated hero media frame.');
$assert(!str_contains($home, 'class="signal-core"'), 'The abstract operating-model graphic should be removed from the hero.');
$heroAssetPath = dirname(__DIR__) . '/public/assets/images/hero-data-center.jpg';
$assert(is_file($heroAssetPath) && filesize($heroAssetPath) > 100000, 'The supplied hero photograph should be bundled as a non-empty local asset.');
$heroDimensions = getimagesize($heroAssetPath);
$assert(is_array($heroDimensions) && $heroDimensions[0] === 1920 && $heroDimensions[1] === 1047, 'The bundled hero should preserve the supplied source resolution.');
$assert(str_contains($home, '<span class="company-name">T&amp;Tech Consulting Group</span>'), 'The header should render the full company name as text.');
$headerStart = strpos($home, '<header');
$headerEnd = strpos($home, '</header>');
$headerMarkup = $headerStart !== false && $headerEnd !== false ? substr($home, $headerStart, $headerEnd - $headerStart) : '';
$assert(!str_contains($headerMarkup, 'ttechcg-mark.svg'), 'The header should not render the logo image.');

$about = $view->render('site/about', array_merge($common, [
    'pageTitle' => 'About T&Tech',
    'pageDescription' => 'Test description',
    'activePage' => 'about',
]));
$assert(str_contains($about, 'Headquartered in Cameroon'), 'The about page should identify Cameroon as the company headquarters.');
$assert(str_contains($about, 'locations in Bamenda and Douala'), 'The about page should identify both Cameroon locations.');

$contact = $view->render('contact/index', array_merge($common, [
    'pageTitle' => 'Contact T&Tech',
    'pageDescription' => 'Test description',
    'activePage' => 'contact',
    'csrfToken' => 'test-token',
    'captcha' => ['question' => '7 + 4', 'nonce' => 'test-captcha-nonce'],
    'flash' => null,
    'errors' => [],
    'old' => [],
    'contactOperational' => true,
]));
$assert(str_contains($contact, 'Commercial Avenue'), 'The contact page should show the Bamenda street location.');
$assert(str_contains($contact, 'Bamenda, North-West Region'), 'The contact page should show the full Bamenda location.');
$assert(str_contains($contact, 'Bonapriso'), 'The contact page should show the Douala neighbourhood.');
$assert(str_contains($contact, 'Douala, Littoral Region'), 'The contact page should show the full Douala location.');
$assert(str_contains($contact, 'mailto:info@ttechcg.com'), 'The contact page should provide the direct inquiry destination.');
$assert(str_contains($contact, 'forwarded to <a href="mailto:info@ttechcg.com"'), 'The form should disclose where inquiries are forwarded.');
$assert(str_contains($contact, 'method="post" action="/contact"'), 'The contact form should post to the inquiry controller.');
$assert(str_contains($contact, 'name="captcha_nonce" value="test-captcha-nonce"'), 'The contact form should include the server-issued CAPTCHA nonce.');
$assert(str_contains($contact, 'What is 7 + 4?'), 'The contact form should present the CAPTCHA challenge.');
$assert(str_contains($contact, 'name="captcha_answer"'), 'The contact form should require a CAPTCHA answer.');
$assert(str_contains($contact, 'type="checkbox" name="privacy_consent" value="1" required'), 'The contact form should include an unchecked, required privacy opt-in.');
$assert(str_contains($contact, 'I consent to T&amp;Tech processing'), 'The privacy opt-in should state its specific purpose.');
$assert(!str_contains($contact, 'name="privacy_consent" value="1" required checked'), 'The privacy opt-in should not be preselected.');

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
    'pageTitle' => 'Cash shipment pickup sheet',
    'pageDescription' => 'Test description',
    'pageRobots' => 'noindex, nofollow',
    'activePage' => 'pickupsheet',
    'csrfToken' => 'pickup-csrf-token',
    'captcha' => ['question' => '9 + 2', 'nonce' => 'pickup-captcha-nonce'],
    'flash' => null,
    'errors' => [],
    'old' => [],
    'pickupOperational' => true,
]));
$assert(str_contains($product, 'pickupsheet'), 'The Pickupsheet product page should render.');
$assert(str_contains($product, 'Cash shipments'), 'The PDF cash-shipment section should render.');
$assert(str_contains($product, 'name="agent_name"'), 'The pickup form should collect the agent name.');
$assert(str_contains($product, 'name="collection_date"'), 'The pickup form should collect the sheet date.');
$assert(str_contains($product, 'shipments[0][consignor]'), 'The pickup form should collect a consignor for each row.');
$assert(str_contains($product, 'shipments[0][awb_number]'), 'The pickup form should collect the AWB number from the PDF.');
$assert(str_contains($product, 'shipments[0][destination]'), 'The pickup form should collect the destination code from the PDF.');
$assert(str_contains($product, 'shipments[0][amount]'), 'The pickup form should collect cash amounts from the PDF.');
$assert(str_contains($product, 'shipments[0][pieces]'), 'The pickup form should collect piece counts from the PDF.');
$assert(str_contains($product, 'shipments[0][weight_kg]'), 'The pickup form should collect shipment weight from the PDF.');
$assert(str_contains($product, 'shipments[0][collection_time]'), 'The pickup form should collect the collection time from the PDF.');
$assert(str_contains($product, 'shipments[0][checked_by]'), 'The pickup form should collect the checker name from the PDF.');
$assert(str_contains($product, 'data-shipment-count'), 'The pickup form should calculate shipments collected.');
$assert(str_contains($product, 'data-shipment-total'), 'The pickup form should calculate total cash received.');
$assert(str_contains($product, 'name="captcha_nonce" value="pickup-captcha-nonce"'), 'The pickup form should include first-party human verification.');
$assert(str_contains($product, 'type="checkbox" name="privacy_consent" value="1" required'), 'The pickup form should require explicit privacy consent.');
$assert(!str_contains($product, 'name="privacy_consent" value="1" required checked'), 'Pickup-sheet consent should not be preselected.');
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
$assert(str_contains($privacy, 'agent name, collection date, consignor, AWB number'), 'The privacy notice should disclose pickup-sheet fields.');
$assert(str_contains($privacy, 'record and reconcile cash shipment collections'), 'The privacy notice should state the pickup-sheet processing purpose.');
$assert(str_contains($privacy, 'inquiry and pickup-sheet forms require an explicit, unchecked opt-in'), 'Both personal-data forms should require explicit consent.');
$assert(str_contains($privacy, 'T&amp;Tech Consulting Group'), 'The privacy notice should identify the data controller.');
$assert(str_contains($privacy, 'We do not sell inquiry information.'), 'The privacy notice should state the use limitation.');
$assert(str_contains($privacy, 'forwarded by email to info@ttechcg.com'), 'The privacy notice should disclose the inquiry destination.');
$assert(str_contains($privacy, 'require an explicit, unchecked opt-in'), 'The privacy notice should explain the consent control.');
$assert(str_contains($privacy, 'withdraw that consent'), 'The privacy notice should explain how consent can be withdrawn.');
$assert(str_contains($privacy, 'one first-party session cookie'), 'The privacy notice should disclose the essential security cookie.');
$assert(str_contains($privacy, 'not used for advertising or cross-site tracking'), 'The privacy notice should state the essential cookie limitation.');
$assert(str_contains($privacy, 'Optional Google Analytics'), 'The privacy notice should disclose the analytics service.');
$assert(str_contains($privacy, 'G-WVFXFB5H3M'), 'The privacy notice should identify the configured Analytics property.');
$assert(str_contains($privacy, 'declining sends no analytics data to Google'), 'The privacy notice should explain the effect of declining analytics.');
$assert(str_contains($privacy, 'Cookie settings'), 'The privacy notice should explain how to change the analytics choice.');

$environmentExample = file_get_contents(dirname(__DIR__) . '/.env.example');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'APP_TIMEZONE=Africa/Douala'), 'The environment example should use Cameroon time.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'CONTACT_EMAIL=info@ttechcg.com'), 'The environment example should route production inquiries to the company mailbox.');

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
$assert(is_string($styles) && str_contains($styles, '.partner-logo--aws'), 'The partner logo wall should preserve the AWS dark logo treatment.');
$assert(is_string($styles) && str_contains($styles, '.partner-logo--dhl'), 'The partner logo wall should include the DHL-yellow logo treatment.');
$assert(is_string($styles) && str_contains($styles, '/* Mobile experience hardening */'), 'The site should include focused mobile overrides.');
$assert(is_string($styles) && str_contains($styles, 'height: calc(100dvh - 68px);'), 'The mobile navigation should respect the dynamic viewport height.');
$assert(is_string($styles) && str_contains($styles, 'font-size: 16px;'), 'Mobile form controls should prevent automatic input zoom.');
$assert(is_string($styles) && str_contains($styles, '.contact-destination'), 'The direct inquiry destination should have a responsive presentation.');
$assert(is_string($styles) && str_contains($styles, '.office-grid'), 'The two Cameroon offices should use the responsive location layout.');
$assert(is_string($styles) && str_contains($styles, '.captcha-fieldset'), 'The human verification control should have responsive form styling.');
$assert(is_string($styles) && str_contains($styles, '.hero-media'), 'The landing hero photograph should have a dedicated responsive frame.');
$assert(is_string($styles) && str_contains($styles, 'aspect-ratio: 16 / 9;'), 'The landing hero photograph should display at a 16:9 ratio.');
$assert(is_string($styles) && str_contains($styles, 'object-fit: cover;'), 'The landing hero photograph should fill its 16:9 frame without distortion.');
$assert(is_string($styles) && str_contains($styles, '.consent-field'), 'The required privacy opt-in should have accessible responsive styling.');
$assert(is_string($styles) && str_contains($styles, '.analytics-consent'), 'The analytics preference panel should have responsive styling.');
$assert(is_string($styles) && str_contains($styles, '/* Pickupsheet cash-shipment entry */'), 'The pickup-sheet form should have a dedicated visual system.');
$assert(is_string($styles) && str_contains($styles, '.shipment-row'), 'Shipment rows should have responsive form styling.');
$assert(is_string($styles) && str_contains($styles, 'content: attr(data-label);'), 'Shipment rows should expose their field labels in the mobile card layout.');

$script = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$assert(is_string($script) && str_contains($script, "event.key === 'Escape'"), 'The mobile navigation should close with Escape.');
$assert(is_string($script) && str_contains($script, "toggleAttribute('inert', open)"), 'The open mobile navigation should isolate background content.');
$assert(is_string($script) && str_contains($script, "matchMedia('(min-width: 821px)')"), 'The navigation state should reset when returning to desktop width.');
$assert(is_string($script) && str_contains($script, "document.querySelector('[data-pickup-form]')"), 'The pickup form should initialize its dynamic row editor.');
$assert(is_string($script) && str_contains($script, 'maximumRows = 50'), 'The browser should enforce the server shipment-row limit.');
$assert(is_string($script) && str_contains($script, 'numberFormatter.format(total)'), 'The browser should calculate and format cash totals.');

$analyticsScript = file_get_contents(dirname(__DIR__) . '/public/assets/analytics.js');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "const measurementId = 'G-WVFXFB5H3M'"), 'The supplied Google Analytics measurement ID should be configured exactly.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, 'https://www.googletagmanager.com/gtag/js?id=${measurementId}'), 'The consent loader should use the official Google tag endpoint.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "analytics_storage: 'denied'"), 'Analytics storage should be denied by default.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "ad_storage: 'denied'"), 'Advertising storage should remain denied.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "window.gtag('config', measurementId)"), 'The accepted tag should configure the supplied measurement ID.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "preference === 'granted'"), 'The Google tag should load automatically only after a saved grant.');

$database = file_get_contents(dirname(__DIR__) . '/src/Shared/Infrastructure/Database.php');
$assert(is_string($database) && str_contains($database, "extension_loaded('pdo_mysql')"), 'The application should use PDO MySQL.');

$consentMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/002_add_inquiry_privacy_consent.sql');
$assert(is_string($consentMigration) && str_contains($consentMigration, 'privacy_consent_at'), 'The database should retain inquiry consent timestamps.');
$assert(is_string($consentMigration) && str_contains($consentMigration, 'privacy_notice_version'), 'The database should retain privacy-notice versions.');
$mysqlRepository = file_get_contents(dirname(__DIR__) . '/src/Modules/Contact/Infrastructure/MysqlInquiryRepository.php');
$assert(is_string($mysqlRepository) && str_contains($mysqlRepository, ':privacy_consent_at'), 'MySQL inquiry persistence should write the consent timestamp.');
$pickupMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/003_create_pickup_sheets.sql');
$assert(is_string($pickupMigration) && str_contains($pickupMigration, 'CREATE TABLE IF NOT EXISTS pickup_sheets'), 'The database should store pickup-sheet headers.');
$assert(is_string($pickupMigration) && str_contains($pickupMigration, 'CREATE TABLE IF NOT EXISTS pickup_shipments'), 'The database should store repeatable shipment rows.');
$assert(is_string($pickupMigration) && str_contains($pickupMigration, 'ON DELETE CASCADE'), 'Shipment rows should remain part of the pickup-sheet aggregate.');
$pickupMysqlRepository = file_get_contents(dirname(__DIR__) . '/src/Modules/Pickupsheet/Infrastructure/MysqlPickupSheetRepository.php');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'beginTransaction()'), 'Pickup-sheet headers and rows should save transactionally.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, ':total_cash_received_xaf'), 'MySQL persistence should store the server-calculated XAF total.');
$bootstrap = file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'httponly' => true"), 'The security session cookie should be inaccessible to client-side scripts.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'samesite' => 'Lax'"), 'The security session cookie should use a SameSite policy.');

echo "All application tests passed.\n";
