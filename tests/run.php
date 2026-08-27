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
use App\Modules\Pickupsheet\UI\PickupsheetAuthController;
use App\Modules\Pickupsheet\UI\PickupsheetController;
use App\Modules\Site\UI\SiteController;
use App\Shared\Http\Request;
use App\Shared\Infrastructure\DemoRecordsUserRepository;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
use App\Shared\Security\JumpCloudOidcProvider;
use App\Shared\Security\OidcHttpClient;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\RecordsUserService;
use App\Shared\Security\SecurityLogger;
use App\Shared\Spreadsheet\XlsxWriter;
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
            'collection_time' => '99:99',
            'checked_by' => 'Elizabeth A',
        ],
        [
            'consignor' => 'Gumia Ngondab',
            'awb_number' => '1589328716',
            'destination' => 'BUD',
            'amount' => '56100',
            'pieces' => '1',
            'weight_kg' => '0.500',
            'checked_by' => 'Elizabeth A',
        ],
    ],
]);
$assert($pickupSheet->id === 1, 'A pickup sheet should receive a repository ID.');
$assert((bool) preg_match('/^PS-20260729-[A-F0-9]{32}$/', $pickupSheet->referenceNumber), 'A pickup sheet should receive a 128-bit date-based reference number.');
$assert($pickupSheet->shipmentCount() === 2, 'All completed shipment rows should be collected.');
$assert($pickupSheet->totalCashReceivedXaf === 115700, 'The XAF total should be recalculated from shipment rows.');
$assert($pickupSheet->shipments[0]->destination === 'DCA', 'Destination codes should be normalized to uppercase.');
$assert($pickupSheet->shipments[0]->weightKg === '0.500', 'Shipment weight should be normalized for MySQL decimals.');
$assert((bool) preg_match('/^[0-9]{2}:[0-9]{2}$/', $pickupSheet->shipments[0]->collectionTime), 'Shipment collection time should be assigned automatically in hour-and-minute format.');
$assert($pickupSheet->shipments[0]->collectionTime === $pickupSheet->shipments[1]->collectionTime, 'Every shipment on a sheet should use the same server-recorded submission time.');
$assert($pickupSheet->shipments[0]->collectionTime !== '99:99', 'A client-supplied collection time should be ignored.');
$assert($pickupSheet->privacyConsentAt !== '', 'Pickup-sheet consent should retain a timestamp.');
$assert(($pickupService->recent(1)[0]->referenceNumber ?? '') === $pickupSheet->referenceNumber, 'Recent pickup sheets should be available to the submissions view.');
$assert($pickupService->findByReference($pickupSheet->referenceNumber)?->agentName === 'Edmund Ngochi', 'A pickup sheet should be retrievable by its reference number.');
$assert($pickupService->findByReference('invalid-reference') === null, 'Invalid pickup-sheet references should not reach persistence.');

for ($sheetIndex = 2; $sheetIndex <= 12; $sheetIndex++) {
    $pickupService->submit([
        'agent_name' => 'Pagination Agent ' . $sheetIndex,
        'collection_date' => '2026-07-29',
        'privacy_consent' => '1',
        'shipments' => [[
            'consignor' => 'Pagination Client ' . $sheetIndex,
            'awb_number' => str_pad((string) $sheetIndex, 10, '0', STR_PAD_LEFT),
            'destination' => 'DLA',
            'amount' => '1000',
            'pieces' => '1',
            'weight_kg' => '0.5',
            'checked_by' => 'Pagination Checker',
        ]],
    ]);
}
$firstPickupPage = $pickupService->paginated(1, 10);
$secondPickupPage = $pickupService->paginated(2, 10);
$assert($firstPickupPage['totalRecords'] === 12, 'Pagination should count all submitted pickup sheets.');
$assert($firstPickupPage['totalPages'] === 2, 'Twelve pickup sheets should produce two ten-record pages.');
$assert(count($firstPickupPage['items']) === 10, 'The first pickup-sheet page should contain exactly ten records.');
$assert(count($secondPickupPage['items']) === 2, 'The second pickup-sheet page should contain the remaining records.');
$assert($secondPickupPage['page'] === 2, 'Pagination should retain the requested valid page.');
$assert($pickupService->paginated(999, 10)['page'] === 2, 'Pagination should clamp out-of-range pages to the final page.');

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
            'checked_by' => 'Test Checker',
        ]],
    ]);
} catch (RuntimeException) {
    $pickupStorageFailed = true;
}
$assert($pickupStorageFailed, 'Unavailable production pickup storage should fail explicitly.');

$request = new Request('GET', '/products', [], [], '');
$assert($request->path === '/products', 'Request should retain the routed path.');
$arrayRequest = new Request('POST', '/dhl/pickupsheet', [], ['shipments' => [['awb_number' => '1234567890']]], '');
$assert(($arrayRequest->arrayInput('shipments')[0]['awb_number'] ?? '') === '1234567890', 'Request should expose nested shipment arrays.');
$rawPasswordRequest = new Request('POST', '/protected', [], ['password' => '  retain-spaces  '], '');
$assert($rawPasswordRequest->rawInput('password') === '  retain-spaces  ', 'Password input should not be silently trimmed.');
$basicRequest = new Request('GET', '/protected', [], [], '', [
    'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('records-admin:test-password'),
    'HTTP_CF_CONNECTING_IP' => '203.0.113.15',
]);
$assert($basicRequest->basicCredentials() === ['records-admin', 'test-password'], 'Request should safely parse a Basic authorization header.');
$assert($basicRequest->clientIdentifier() === hash('sha256', '203.0.113.15'), 'Request should hash the validated Cloudflare client address.');

$rateLimitDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttechcg-rate-limit-' . bin2hex(random_bytes(8));
$rateLimiterTest = new RateLimiter($rateLimitDirectory);
$assert($rateLimiterTest->consume('test', 'client', 2, 60) === 0, 'The first rate-limited request should be allowed.');
$assert($rateLimiterTest->consume('test', 'client', 2, 60) === 0, 'Requests within the persistent limit should be allowed.');
$assert($rateLimiterTest->consume('test', 'client', 2, 60) > 0, 'Requests beyond the persistent limit should be denied with a retry time.');
foreach (glob($rateLimitDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $rateLimitFile) {
    unlink($rateLimitFile);
}
rmdir($rateLimitDirectory);

$config = require dirname(__DIR__) . '/config/app.php';
$view = new View(dirname(__DIR__) . '/views');
$paginationFixture = $view->renderPartial('pickupsheet/_submission-records', [
    'basePath' => '',
    'pickupOperational' => true,
    'pickupSheets' => $secondPickupPage['items'],
    'pagination' => $secondPickupPage,
    'errors' => [],
    'canPrint' => true,
    'canExport' => true,
]);
$assert(substr_count($paginationFixture, '<article class="pickup-record">') === 2, 'The second ten-record page should render only its two remaining sheets.');
$assert(str_contains($paginationFixture, 'Page 2 of 2 · 12 records'), 'The pagination fragment should display accurate page and record totals.');
$assert(str_contains($paginationFixture, 'data-pickup-page="1" rel="prev"'), 'The second page should provide a normal-link fallback to the previous page.');
$assert(!str_contains($paginationFixture, 'data-pickup-page="3"'), 'The final page should not link beyond the available records.');
$healthResponse = (new SiteController($view, $config, 'MySQL connected', true))->health(new Request('GET', '/health'));
$assert($healthResponse->body() === '{"status":"ok"}', 'The public health endpoint should not expose backend component details.');
$assert(($healthResponse->headers()['Cache-Control'] ?? '') === 'no-store', 'Health status should not be cached.');
$disabledRateLimiter = new RateLimiter('', false);
$disabledSecurityLogger = new SecurityLogger(false);
$recordsUsername = 'records-admin';
$recordsPassword = 'test-records-password';
$viewerUsername = 'records-viewer';
$viewerPassword = 'test-viewer-password';
$operatorUsername = 'records-operator';
$operatorPassword = 'test-operator-password';
$recordsUserRepository = new DemoRecordsUserRepository();
$recordsAccess = new RecordsAccess([
    [
        'username' => $recordsUsername,
        'firstName' => 'Records',
        'lastName' => 'Administrator',
        'passwordHash' => password_hash($recordsPassword, PASSWORD_DEFAULT),
        'role' => 'admin',
    ],
    [
        'username' => $viewerUsername,
        'firstName' => 'Victoria',
        'lastName' => 'Viewer',
        'passwordHash' => password_hash($viewerPassword, PASSWORD_DEFAULT),
        'role' => 'viewer',
    ],
    [
        'username' => $operatorUsername,
        'firstName' => 'Oliver',
        'lastName' => 'Operator',
        'passwordHash' => password_hash($operatorPassword, PASSWORD_DEFAULT),
        'role' => 'operator',
    ],
], $recordsUserRepository);
$recordsSession = new RecordsSession();
$recordsUserService = new RecordsUserService($recordsUserRepository, $recordsAccess->environmentUsernames());
$recordsServer = [
    'PHP_AUTH_USER' => $recordsUsername,
    'PHP_AUTH_PW' => $recordsPassword,
    'REMOTE_ADDR' => '203.0.113.20',
];
$viewerServer = [
    'PHP_AUTH_USER' => $viewerUsername,
    'PHP_AUTH_PW' => $viewerPassword,
    'REMOTE_ADDR' => '203.0.113.24',
];
$operatorServer = [
    'PHP_AUTH_USER' => $operatorUsername,
    'PHP_AUTH_PW' => $operatorPassword,
    'REMOTE_ADDR' => '203.0.113.25',
];
$adminPrincipal = $recordsAccess->authenticate(new Request('GET', '/protected', [], [], '', $recordsServer));
$viewerPrincipal = $recordsAccess->authenticate(new Request('GET', '/protected', [], [], '', $viewerServer));
$operatorPrincipal = $recordsAccess->authenticate(new Request('GET', '/protected', [], [], '', $operatorServer));
$assert($adminPrincipal?->role === 'admin' && $adminPrincipal->can('manage') && $adminPrincipal->can('dashboard'), 'An admin should manage users and receive the activity dashboard.');
$assert($adminPrincipal?->fullName() === 'Records Administrator', 'Authenticated principals should expose the account first and last name.');
$assert($adminPrincipal?->can('edit') === true && $adminPrincipal->can('mark_paid') === true && $adminPrincipal->can('delete') === true, 'An administrator should edit, mark paid, and delete pickup records.');
$assert($viewerPrincipal?->can('create') === true && $viewerPrincipal->can('list') === true && $viewerPrincipal->can('edit') === false && $viewerPrincipal->can('print') === false, 'A viewer should create and view records but cannot edit, print, or export them.');
$assert($operatorPrincipal?->can('edit') === true && $operatorPrincipal->can('mark_paid') === true && $operatorPrincipal->can('delete') === false && $operatorPrincipal->can('print') === true && $operatorPrincipal->can('export') === true, 'An operator should edit, mark paid, print, and export records without delete access.');
$assert($recordsAccess->authenticate(new Request('GET', '/protected', [], [], '', [
    'PHP_AUTH_USER' => $viewerUsername,
    'PHP_AUTH_PW' => 'incorrect-password',
])) === null, 'RBAC authentication should reject an incorrect password.');
$reservedManagedUsernameRejected = false;
try {
    $recordsUserService->create([
        'username' => $recordsUsername,
        'first_name' => 'Reserved',
        'last_name' => 'Account',
        'role' => 'viewer',
        'active' => '1',
        'password' => 'reserved-password-123',
        'password_confirmation' => 'reserved-password-123',
    ], $adminPrincipal);
} catch (InvalidArgumentException $exception) {
    $reservedManagedUsernameRejected = str_contains($exception->getMessage(), 'reserved');
}
$assert($reservedManagedUsernameRejected, 'A managed account must not shadow an environment-defined administrator.');
$missingManagedNameRejected = false;
try {
    $recordsUserService->create([
        'username' => 'missing-name-user',
        'first_name' => '',
        'last_name' => '',
        'role' => 'viewer',
        'active' => '1',
        'password' => 'missing-name-password-123',
        'password_confirmation' => 'missing-name-password-123',
    ], $adminPrincipal);
} catch (InvalidArgumentException $exception) {
    $missingManagedNameRejected = str_contains($exception->getMessage(), 'First name is required');
}
$assert($missingManagedNameRejected, 'A managed account must have a required first and last name.');
$assert(!(new RecordsAccess([[
    'username' => 'records-owner',
    'passwordHash' => password_hash('test-owner-password', PASSWORD_DEFAULT),
    'role' => 'owner',
]]))->isConfigured(), 'An undefined role should fail closed.');
$rogueRecordsUserRepository = new DemoRecordsUserRepository();
$rogueRecordsUserRepository->create(
    'database-admin',
    'Database',
    'Administrator',
    password_hash('database-admin-password', PASSWORD_DEFAULT),
    'admin',
    true,
    'test-actor',
);
$rogueRecordsAccess = new RecordsAccess([], $rogueRecordsUserRepository);
$assert($rogueRecordsAccess->authenticate(new Request('GET', '/protected', [], [], '', [
    'PHP_AUTH_USER' => 'database-admin',
    'PHP_AUTH_PW' => 'database-admin-password',
])) === null, 'A database role must never be able to escalate itself to administrator.');

$oidcBase64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$oidcOpenSslConfig = tempnam(sys_get_temp_dir(), 'ttechcg-oidc-');
$assert(is_string($oidcOpenSslConfig), 'OIDC tests should create an isolated OpenSSL configuration.');
file_put_contents($oidcOpenSslConfig, "openssl_conf = openssl_init\n[openssl_init]\nproviders = provider_sect\n[provider_sect]\ndefault = default_sect\n[default_sect]\nactivate = 1\n");
$oidcPrivateKey = openssl_pkey_new([
    'config' => $oidcOpenSslConfig,
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
unlink($oidcOpenSslConfig);
$oidcKeyDetails = $oidcPrivateKey !== false ? openssl_pkey_get_details($oidcPrivateKey) : false;
$assert(is_array($oidcKeyDetails) && is_array($oidcKeyDetails['rsa'] ?? null), 'OIDC tests should create an isolated RSA signing key.');
$oidcHttpClient = new class implements OidcHttpClient {
    /** @var array<string, mixed> */
    public array $tokenResponse = [];
    /** @var array<string, mixed> */
    public array $userInfo = [];
    /** @var array<string, mixed> */
    public array $jwks = [];

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->tokenResponse;
    }

    public function getJson(string $url, array $headers = []): array
    {
        return str_ends_with($url, '/.well-known/jwks.json') ? $this->jwks : $this->userInfo;
    }
};
$oidcSettings = [
    'enabled' => true,
    'issuer' => 'https://oauth.id.jumpcloud.com/',
    'client_id' => 'pickupsheet-test-client',
    'client_secret' => 'test-client-secret-not-for-production',
    'redirect_uri' => 'https://ttechcg.com/dhl/pickupsheet/auth/jumpcloud/callback',
    'groups_claim' => 'groups',
    'client_authentication' => 'client_secret_basic',
    'admin_group' => 'Pickupsheet Admins',
    'operator_group' => 'Pickupsheet Operators',
    'viewer_group' => 'Pickupsheet Viewers',
];
$oidcProvider = new JumpCloudOidcProvider($oidcSettings, $oidcHttpClient);
$assert($oidcProvider->isConfigured(), 'A complete JumpCloud OIDC configuration should be enabled.');
$assert(!(new JumpCloudOidcProvider(array_merge($oidcSettings, ['issuer' => 'https://attacker.example/']), $oidcHttpClient))->isConfigured(), 'JumpCloud OIDC should reject untrusted issuer hosts.');
$oidcHttpClient->jwks = ['keys' => [[
    'kid' => 'pickupsheet-test-key',
    'kty' => 'RSA',
    'alg' => 'RS256',
    'use' => 'sig',
    'n' => $oidcBase64Url((string) ($oidcKeyDetails['rsa']['n'] ?? '')),
    'e' => $oidcBase64Url((string) ($oidcKeyDetails['rsa']['e'] ?? '')),
]]];
$oidcHttpClient->userInfo = [
    'sub' => 'jumpcloud-user-123',
    'email' => 'operator@example.com',
    'email_verified' => true,
    'given_name' => 'Olivia',
    'family_name' => 'Operator',
];
$signOidcToken = static function (string $nonce, array $groups) use ($oidcBase64Url, $oidcPrivateKey): string {
    $header = $oidcBase64Url((string) json_encode(['alg' => 'RS256', 'kid' => 'pickupsheet-test-key', 'typ' => 'JWT']));
    $claims = $oidcBase64Url((string) json_encode([
        'iss' => 'https://oauth.id.jumpcloud.com/',
        'sub' => 'jumpcloud-user-123',
        'aud' => 'pickupsheet-test-client',
        'iat' => time(),
        'exp' => time() + 600,
        'nonce' => $nonce,
        'at_hash' => $oidcBase64Url(substr(hash('sha256', 'test-access-token', true), 0, 16)),
        'email' => 'operator@example.com',
        'email_verified' => true,
        'given_name' => 'Olivia',
        'family_name' => 'Operator',
        'groups' => $groups,
    ]));
    $signingInput = $header . '.' . $claims;
    $signature = '';
    if ($oidcPrivateKey === false || !openssl_sign($signingInput, $signature, $oidcPrivateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign the OIDC test token.');
    }
    return $signingInput . '.' . $oidcBase64Url($signature);
};

$_SESSION = [];
$oidcAuthorizationUrl = $oidcProvider->authorizationUrl();
parse_str((string) parse_url($oidcAuthorizationUrl, PHP_URL_QUERY), $oidcAuthorizationQuery);
$oidcNonce = (string) ($_SESSION['_pickupsheet_jumpcloud_oidc']['nonce'] ?? '');
$oidcState = (string) ($oidcAuthorizationQuery['state'] ?? '');
$assert(str_starts_with($oidcAuthorizationUrl, 'https://oauth.id.jumpcloud.com/oauth2/auth?'), 'JumpCloud login should use the regional authorization endpoint.');
$assert(($oidcAuthorizationQuery['code_challenge_method'] ?? '') === 'S256' && $oidcNonce !== '' && $oidcState !== '', 'JumpCloud login should issue state, nonce, and PKCE S256 protection.');
$oidcHttpClient->tokenResponse = [
    'access_token' => 'test-access-token',
    'id_token' => $signOidcToken($oidcNonce, ['Pickupsheet Viewers', 'Pickupsheet Operators']),
    'token_type' => 'Bearer',
];
$jumpCloudPrincipal = $oidcProvider->authenticate('valid-authorization-code', $oidcState);
$assert($jumpCloudPrincipal->role === 'operator' && $jumpCloudPrincipal->identityProvider === 'jumpcloud', 'JumpCloud group membership should map to the highest approved Pickupsheet role.');
$assert($jumpCloudPrincipal->fullName() === 'Olivia Operator' && $jumpCloudPrincipal->username === 'operator@example.com', 'JumpCloud profile claims should populate the account identity and Check By name.');
$recordsSession->login($jumpCloudPrincipal);
$resolvedJumpCloudPrincipal = $recordsSession->principal(new RecordsAccess());
$assert($resolvedJumpCloudPrincipal?->role === 'operator' && $resolvedJumpCloudPrincipal->identityProvider === 'jumpcloud', 'A verified JumpCloud identity should remain valid in the protected first-party session.');
$oidcReplayRejected = false;
try {
    $oidcProvider->authenticate('valid-authorization-code', $oidcState);
} catch (RuntimeException) {
    $oidcReplayRejected = true;
}
$assert($oidcReplayRejected, 'A JumpCloud authorization transaction should be single-use.');
$recordsSession->logout();

$previousRbacUsers = getenv('PICKUPSHEET_RBAC_USERS');
$previousLegacyUser = getenv('PICKUPSHEET_RECORDS_USER');
$previousLegacyHash = getenv('PICKUPSHEET_RECORDS_PASSWORD_HASH');
$environmentViewerHash = password_hash('environment-viewer-password', PASSWORD_DEFAULT);
putenv('PICKUPSHEET_RBAC_USERS=environment.viewer@example.com|viewer|Environment|Viewer|' . $environmentViewerHash);
putenv('PICKUPSHEET_RECORDS_USER');
putenv('PICKUPSHEET_RECORDS_PASSWORD_HASH');
$environmentAccess = RecordsAccess::fromEnvironment();
$environmentPrincipal = $environmentAccess->authenticate(new Request('GET', '/protected', [], [], '', [
    'PHP_AUTH_USER' => 'ENVIRONMENT.VIEWER@EXAMPLE.COM',
    'PHP_AUTH_PW' => 'environment-viewer-password',
]));
$assert($environmentPrincipal?->role === 'viewer' && $environmentPrincipal->fullName() === 'Environment Viewer', 'RBAC accounts should accept a case-insensitive email login ID and names from the server environment.');

putenv('PICKUPSHEET_RBAC_USERS');
putenv('PICKUPSHEET_RECORDS_USER=legacy-admin');
putenv('PICKUPSHEET_RECORDS_PASSWORD_HASH=' . password_hash('legacy-admin-password', PASSWORD_DEFAULT));
$legacyAccess = RecordsAccess::fromEnvironment();
$legacyPrincipal = $legacyAccess->authenticate(new Request('GET', '/protected', [], [], '', [
    'PHP_AUTH_USER' => 'legacy-admin',
    'PHP_AUTH_PW' => 'legacy-admin-password',
]));
$assert($legacyPrincipal?->role === 'admin' && $legacyPrincipal->can('manage'), 'Legacy records credentials should remain compatible as an admin account.');

foreach ([
    'PICKUPSHEET_RBAC_USERS' => $previousRbacUsers,
    'PICKUPSHEET_RECORDS_USER' => $previousLegacyUser,
    'PICKUPSHEET_RECORDS_PASSWORD_HASH' => $previousLegacyHash,
] as $environmentKey => $environmentValue) {
    putenv($environmentValue === false ? $environmentKey : $environmentKey . '=' . $environmentValue);
}
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
    $disabledRateLimiter,
    $disabledSecurityLogger,
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
    $recordsAccess,
    $recordsSession,
    $recordsUserService,
    $disabledRateLimiter,
    $disabledSecurityLogger,
);
$pickupAuthController = new PickupsheetAuthController(
    $view,
    $pickupCsrf,
    $recordsAccess,
    $recordsSession,
    $disabledRateLimiter,
    $disabledSecurityLogger,
    $config,
);

$jumpCloudAuthController = new PickupsheetAuthController(
    $view,
    $pickupCsrf,
    $recordsAccess,
    $recordsSession,
    $disabledRateLimiter,
    $disabledSecurityLogger,
    $config,
    $oidcProvider,
);
$jumpCloudLoginPage = $jumpCloudAuthController->login(new Request('GET', '/dhl/pickupsheet/login'));
$assert(str_contains($jumpCloudLoginPage->body(), 'Continue with JumpCloud'), 'Configured Pickupsheet login should offer JumpCloud SSO.');
$assert(str_contains($jumpCloudLoginPage->body(), 'Sign in with local account'), 'Local account login should remain available alongside JumpCloud.');
$assert(str_contains($jumpCloudLoginPage->body(), 'autocomplete="current-password"'), 'Configured Pickupsheet login should retain the local password form.');
$jumpCloudLocalLogin = $jumpCloudAuthController->authenticate(new Request('POST', '/dhl/pickupsheet/login', [], [
    '_token' => $pickupCsrf->token(),
    'username' => $recordsUsername,
    'password' => $recordsPassword,
]));
$assert($jumpCloudLocalLogin->status() === 303 && ($jumpCloudLocalLogin->headers()['Location'] ?? '') === '/dhl/pickupsheet/dashboard', 'A local account should remain usable when JumpCloud is configured.');
$recordsSession->logout();
$jumpCloudStart = $jumpCloudAuthController->jumpCloudStart(new Request('GET', '/dhl/pickupsheet/auth/jumpcloud'));
$jumpCloudStartLocation = (string) ($jumpCloudStart->headers()['Location'] ?? '');
parse_str((string) parse_url($jumpCloudStartLocation, PHP_URL_QUERY), $jumpCloudStartQuery);
$jumpCloudCallbackNonce = (string) ($_SESSION['_pickupsheet_jumpcloud_oidc']['nonce'] ?? '');
$oidcHttpClient->tokenResponse = [
    'access_token' => 'test-access-token',
    'id_token' => $signOidcToken($jumpCloudCallbackNonce, ['Pickupsheet Admins']),
    'token_type' => 'Bearer',
];
$jumpCloudCallback = $jumpCloudAuthController->jumpCloudCallback(new Request('GET', '/dhl/pickupsheet/auth/jumpcloud/callback', [
    'code' => 'valid-controller-code',
    'state' => (string) ($jumpCloudStartQuery['state'] ?? ''),
], [], '', ['REMOTE_ADDR' => '203.0.113.30']));
$assert($jumpCloudStart->status() === 302 && str_starts_with($jumpCloudStartLocation, 'https://oauth.id.jumpcloud.com/oauth2/auth?'), 'The Pickupsheet SSO route should redirect to JumpCloud.');
$assert($jumpCloudCallback->status() === 303 && ($jumpCloudCallback->headers()['Location'] ?? '') === '/dhl/pickupsheet/dashboard', 'A JumpCloud admin group member should enter the Pickupsheet dashboard.');
$jumpCloudUsersPage = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users'));
$assert(str_contains($jumpCloudUsersPage->body(), 'Password and access managed centrally') && !str_contains($jumpCloudUsersPage->body(), 'name="current_password"'), 'JumpCloud administrators should manage their password and MFA outside Pickupsheet.');
$jumpCloudPasswordReset = $pickupController->resetAdminPassword(new Request('POST', '/dhl/pickupsheet/submissions/users/admin-password', [], [
    '_token' => $pickupCsrf->token(),
    'current_password' => 'not-used',
    'password' => 'not-used-password-123',
    'password_confirmation' => 'not-used-password-123',
]));
$assert($jumpCloudPasswordReset->status() === 303 && str_contains((string) ($_SESSION['_records_users_errors'][0] ?? ''), 'managed in JumpCloud'), 'Pickupsheet should reject local password rotation for a JumpCloud identity.');
$recordsSession->logout();

$lockedPickup = $pickupController->index(new Request('GET', '/dhl/pickupsheet', [], [], ''));
$assert($lockedPickup->status() === 302 && ($lockedPickup->headers()['Location'] ?? '') === '/dhl/pickupsheet/login', 'Pickupsheet should redirect unauthenticated users to its login portal.');
$loginPage = $pickupAuthController->login(new Request('GET', '/dhl/pickupsheet/login'));
$assert($loginPage->status() === 200 && str_contains($loginPage->body(), 'Welcome back.'), 'The Pickupsheet login portal should render directly.');
$assert(str_contains($loginPage->body(), 'autocomplete="current-password"'), 'The login portal should expose password-manager-compatible fields.');
$assert(!str_contains($loginPage->body(), 'class="site-header"') && !str_contains($loginPage->body(), 'class="site-footer"'), 'Pickupsheet pages should omit the corporate header and footer.');
$assert(!str_contains($loginPage->body(), 'data-analytics-consent'), 'The Analytics consent panel should not interrupt the operational Pickupsheet shell.');
$invalidLogin = $pickupAuthController->authenticate(new Request('POST', '/dhl/pickupsheet/login', [], [
    '_token' => $pickupCsrf->token(),
    'username' => $recordsUsername,
    'password' => 'incorrect-password',
], '', ['REMOTE_ADDR' => '203.0.113.20']));
$assert($invalidLogin->status() === 303, 'An invalid portal login should return to the login page.');
$assert(str_contains((string) ($_SESSION['_pickup_login_error'] ?? ''), 'incorrect'), 'An invalid login should show a generic credential error.');
$adminLogin = $pickupAuthController->authenticate(new Request('POST', '/dhl/pickupsheet/login', [], [
    '_token' => $pickupCsrf->token(),
    'username' => $recordsUsername,
    'password' => $recordsPassword,
], '', ['REMOTE_ADDR' => '203.0.113.20']));
$assert($adminLogin->status() === 303 && ($adminLogin->headers()['Location'] ?? '') === '/dhl/pickupsheet/dashboard', 'An administrator should enter the admin dashboard after login.');

$emptyDashboard = $pickupController->dashboard(new Request('GET', '/dhl/pickupsheet/dashboard'));
$assert($emptyDashboard->status() === 200 && str_contains($emptyDashboard->body(), 'Activity dashboard'), 'The administrator should receive the Pickupsheet control panel.');
$assert(str_contains($emptyDashboard->body(), 'Manage users and RBAC'), 'The dashboard should link its user and RBAC controls.');

$openPickup = $pickupController->index(new Request('GET', '/dhl/pickupsheet', [], [], ''));
$assert($openPickup->status() === 200, 'A signed-in administrator should open the pickup form.');
$assert(str_contains($openPickup->body(), 'data-pickup-form'), 'The pickup form should be immediately available.');
$assert(!str_contains($openPickup->body(), 'class="site-header"') && !str_contains($openPickup->body(), 'class="site-footer"'), 'The signed-in Pickupsheet workspace should contain only its operational shell.');
$assert(!str_contains($openPickup->body(), 'dhl-logo.svg'), 'The pickup form should not display the DHL logo.');
$assert(str_contains($openPickup->body(), 'name="shipments[0][checked_by]"') && str_contains($openPickup->body(), 'data-identity-field'), 'The checker identity should be populated from the authenticated account.');
$assert(str_contains($openPickup->body(), 'value="Records Administrator"') && str_contains($openPickup->body(), 'readonly'), 'The account full name should render as a read-only Check By value.');
$assert(($openPickup->headers()['Cache-Control'] ?? '') === 'private, no-store, max-age=0', 'The open pickup form should not be cached.');

$recordsSession->logout();
$deniedSubmissions = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions', [], [], '', [
    'REMOTE_ADDR' => '203.0.113.21',
]));
$assert($deniedSubmissions->status() === 302, 'Submitted sheets should redirect users without a Pickupsheet session.');
$assert(($deniedSubmissions->headers()['Location'] ?? '') === '/dhl/pickupsheet/login', 'Protected records should use the login portal instead of a Basic-auth challenge.');
$deniedExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => 'PS-20260729-AAAAAAAAAAAAAAAA'], [], '', [
    'PHP_AUTH_USER' => $recordsUsername,
    'PHP_AUTH_PW' => 'incorrect-password',
    'REMOTE_ADDR' => '203.0.113.22',
]));
$assert($deniedExport->status() === 302, 'Basic credentials should not bypass the Pickupsheet login portal.');
$recordsSession->login($adminPrincipal);
$openEmptySubmissions = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions', [], [], '', $recordsServer));
$assert($openEmptySubmissions->status() === 200, 'A valid Pickupsheet session should open the submissions view.');
$assert(str_contains($openEmptySubmissions->body(), 'No submitted sheets yet.'), 'The authorised submissions view should show its empty state.');
$missingExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => 'PS-20260729-AAAAAAAAAAAAAAAA'], [], '', $recordsServer));
$assert($missingExport->status() === 404, 'An authorised unknown spreadsheet export should return not found.');

$pickupChallenge = $pickupCaptcha->issue();
$pickupParts = preg_split('/\s+/', $pickupChallenge['question']);
$pickupAnswer = is_array($pickupParts) && $pickupParts[1] === '+'
    ? (string) ((int) $pickupParts[0] + (int) $pickupParts[2])
    : (string) ((int) $pickupParts[0] - (int) $pickupParts[2]);
$pickupControllerResponse = $pickupController->store(new Request('POST', '/dhl/pickupsheet', [], [
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
        'checked_by' => 'Spoofed Checker',
    ]],
], ''));
$assert($pickupControllerResponse->status() === 303, 'A valid pickup sheet should redirect after saving.');
$assert(str_contains((string) ($_SESSION['_pickup_flash'] ?? ''), 'PS-20260729-'), 'The saved-sheet confirmation should display its reference number.');
$assert(str_contains((string) ($_SESSION['_pickup_flash'] ?? ''), '12,000 XAF'), 'The pickup controller should confirm the server-calculated total.');
$savedControllerSheet = $_SESSION['_demo_pickup_sheets'][0] ?? null;
$assert($savedControllerSheet instanceof App\Modules\Pickupsheet\Domain\PickupSheet, 'The controller should persist a pickup-sheet aggregate.');
$assert($savedControllerSheet->status === 'open' && !$savedControllerSheet->isPaid(), 'Every new pickup sheet should start with open status.');
$assert(($savedControllerSheet->shipments[0]->checkedBy ?? '') === 'Records Administrator', 'The server should ignore a submitted checker and persist the authenticated account name.');
$assert((bool) preg_match('/^[0-9]{2}:[0-9]{2}$/', $savedControllerSheet->shipments[0]->collectionTime ?? ''), 'The controller should persist the server-generated submission time.');
$savedReference = $savedControllerSheet->referenceNumber;

$openSubmissions = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions', [], [], '', $recordsServer));
$assert(str_contains($openSubmissions->body(), $savedReference), 'The direct table should show each sheet reference.');
$assert(!str_contains($openSubmissions->body(), 'dhl-logo.svg'), 'The submitted-sheets screen should not display the DHL logo.');
$assert(str_contains($openSubmissions->body(), 'Controller Client'), 'The direct table should show shipment rows.');
$assert(str_contains($openSubmissions->body(), 'Print / PDF'), 'Each submitted sheet should provide a print-to-PDF action.');
$assert(str_contains($openSubmissions->body(), 'Export Excel'), 'Each submitted sheet should provide an Excel export action.');
$assert(str_contains($openSubmissions->body(), 'Manage access'), 'An administrator should receive the account-management action.');
$assert(str_contains($openSubmissions->body(), 'Edit record'), 'An administrator should receive the audited edit action.');
$assert(str_contains($openSubmissions->body(), 'Mark paid'), 'An administrator should be able to change an open sheet to paid.');
$assert(str_contains($openSubmissions->body(), 'data-pickup-delete'), 'An administrator should receive the audited delete action.');
$assert(str_contains($openSubmissions->body(), 'Records are displayed 10 sheets per page.'), 'The records view should disclose its ten-record page size.');
$assert(str_contains($openSubmissions->body(), 'data-pickup-records-spinner'), 'The records view should provide an AJAX loading spinner.');
$assert(str_contains($openSubmissions->body(), 'data-page-endpoint="/dhl/pickupsheet/submissions/page"'), 'The records view should identify its protected pagination endpoint.');
$assert(str_contains($openSubmissions->body(), 'Page 1 of 1'), 'The records view should display its current pagination status.');
$assert(($openSubmissions->headers()['Cache-Control'] ?? '') === 'private, no-store, max-age=0', 'Submitted records should not be cached.');
$pageFragment = $pickupController->submissionsPage(new Request('GET', '/dhl/pickupsheet/submissions/page', ['page' => '1'], [], '', $recordsServer));
$assert($pageFragment->status() === 200, 'A valid Pickupsheet session should load a paginated AJAX fragment.');
$assert(str_contains($pageFragment->body(), 'Controller Client'), 'The AJAX page fragment should contain its shipment records.');
$assert(!str_contains($pageFragment->body(), '<!doctype html>'), 'The AJAX endpoint should return only the replaceable records fragment.');
$recordsSession->logout();
$deniedPageFragment = $pickupController->submissionsPage(new Request('GET', '/dhl/pickupsheet/submissions/page', ['page' => '1'], [], '', [
    'REMOTE_ADDR' => '203.0.113.23',
]));
$assert($deniedPageFragment->status() === 302, 'The AJAX pagination endpoint should redirect an expired session to the login portal.');

$recordsSession->login($viewerPrincipal);
$viewerSubmissions = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions', [], [], '', $viewerServer));
$viewerPageFragment = $pickupController->submissionsPage(new Request('GET', '/dhl/pickupsheet/submissions/page', ['page' => '1'], [], '', $viewerServer));
$viewerPrint = $pickupController->print(new Request('GET', '/dhl/pickupsheet/submissions/print', ['reference' => $savedReference], [], '', $viewerServer));
$viewerExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => $savedReference], [], '', $viewerServer));
$viewerCreate = $pickupController->index(new Request('GET', '/dhl/pickupsheet'));
$viewerEdit = $pickupController->edit(new Request('GET', '/dhl/pickupsheet/submissions/edit', ['reference' => $savedReference]));
$viewerUpdate = $pickupController->updatePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/edit', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$viewerPaid = $pickupController->markPickupSheetPaid(new Request('POST', '/dhl/pickupsheet/submissions/paid', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$viewerDelete = $pickupController->deletePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/delete', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$assert($viewerSubmissions->status() === 200 && $viewerPageFragment->status() === 200, 'A viewer should be able to list and paginate pickup sheets.');
$assert($viewerCreate->status() === 200, 'A viewer should be able to create pickup records.');
$assert($viewerEdit->status() === 403 && $viewerUpdate->status() === 403, 'A viewer must not open or submit generated pickup-sheet edits.');
$assert(!str_contains($viewerSubmissions->body(), 'Edit record'), 'A viewer should not receive the operator-only edit action.');
$assert(!str_contains($viewerSubmissions->body(), 'Mark paid') && !str_contains($viewerSubmissions->body(), 'data-pickup-delete'), 'A viewer should not receive paid-status or delete controls.');
$assert(!str_contains($viewerSubmissions->body(), 'Print / PDF') && !str_contains($viewerSubmissions->body(), 'Export Excel'), 'A viewer should not be shown actions they cannot use.');
$assert(!str_contains($viewerSubmissions->body(), 'Manage access'), 'A viewer should not be shown administrator account controls.');
$assert($viewerPrint->status() === 403 && $viewerExport->status() === 403, 'A viewer should be forbidden from printing and exporting pickup sheets.');
$assert($viewerPaid->status() === 403 && $viewerDelete->status() === 403, 'A viewer should be forbidden from changing status or deleting pickup sheets.');
$assert(!isset($viewerPrint->headers()['WWW-Authenticate']), 'A forbidden authenticated user should not receive another login challenge.');
$assert(($viewerPrint->headers()['Cache-Control'] ?? '') === 'private, no-store, max-age=0', 'RBAC denial responses should not be cached.');

$recordsSession->login($operatorPrincipal);
$operatorPrint = $pickupController->print(new Request('GET', '/dhl/pickupsheet/submissions/print', ['reference' => $savedReference], [], '', $operatorServer));
$operatorExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => $savedReference], [], '', $operatorServer));
$assert($operatorPrint->status() === 200 && $operatorExport->status() === 200, 'An operator should be able to print and export pickup sheets.');
$operatorSubmissions = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions', [], [], '', $operatorServer));
$assert(!str_contains($operatorSubmissions->body(), 'Manage access'), 'An operator should not be shown administrator account controls.');
$assert(str_contains($operatorSubmissions->body(), 'Edit record'), 'An operator should receive record-edit actions.');
$assert(str_contains($operatorSubmissions->body(), 'Mark paid'), 'An operator should receive the open-to-paid status action.');
$assert(!str_contains($operatorSubmissions->body(), 'data-pickup-delete'), 'An operator must not receive the administrator delete action.');
$operatorEdit = $pickupController->edit(new Request('GET', '/dhl/pickupsheet/submissions/edit', ['reference' => $savedReference]));
$assert($operatorEdit->status() === 200 && str_contains($operatorEdit->body(), 'Save audited changes'), 'An operator should open the audited record editor.');
$operatorDashboard = $pickupController->dashboard(new Request('GET', '/dhl/pickupsheet/dashboard'));
$assert($operatorDashboard->status() === 403, 'An operator should not enter the administrator dashboard.');
$operatorDelete = $pickupController->deletePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/delete', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$assert($operatorDelete->status() === 403, 'An operator must not delete pickup sheets.');

$updateByOperator = $pickupController->updatePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/edit', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
    'agent_name' => 'Controller Agent',
    'collection_date' => '2026-07-29',
    'shipments' => [[
        'consignor' => 'Controller Client',
        'awb_number' => '1234567890',
        'destination' => 'DLA',
        'amount' => '13000',
        'pieces' => '2',
        'weight_kg' => '1.25',
        'collection_time' => '10:15',
        'checked_by' => 'Spoofed Operator',
    ]],
], ''));
$assert($updateByOperator->status() === 303, 'An operator should save an audited pickup-sheet correction.');
$updatedControllerSheet = (new PickupSheetService(new DemoPickupSheetRepository()))->findByReference($savedReference);
$assert($updatedControllerSheet?->totalCashReceivedXaf === 13000, 'An operator edit should recalculate and persist the sheet total.');
$assert(($updatedControllerSheet->shipments[0]->collectionTime ?? '') === '10:15', 'An operator should be able to correct collection time.');
$assert(($updatedControllerSheet->shipments[0]->checkedBy ?? '') === 'Oliver Operator', 'An operator edit should stamp Check By with the operator account name.');
$markPaidByOperator = $pickupController->markPickupSheetPaid(new Request('POST', '/dhl/pickupsheet/submissions/paid', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$assert($markPaidByOperator->status() === 303, 'An operator should change an open pickup sheet to paid.');
$paidControllerSheet = (new PickupSheetService(new DemoPickupSheetRepository()))->findByReference($savedReference);
$assert($paidControllerSheet?->status === 'paid' && $paidControllerSheet->isPaid() && $paidControllerSheet->paidAt !== null, 'The paid status and timestamp should persist on the pickup sheet.');

$recordsSession->login($viewerPrincipal);
$viewerUsers = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users', [], [], '', $viewerServer));
$recordsSession->login($operatorPrincipal);
$operatorUsers = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users', [], [], '', $operatorServer));
$assert($viewerUsers->status() === 403 && $operatorUsers->status() === 403, 'Only administrators should reach records-user management.');

$recordsSession->login($adminPrincipal);
$adminEdit = $pickupController->edit(new Request('GET', '/dhl/pickupsheet/submissions/edit', ['reference' => $savedReference]));
$adminUpdate = $pickupController->updatePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/edit', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
    'agent_name' => 'Controller Agent',
    'collection_date' => '2026-07-29',
    'shipments' => [[
        'consignor' => 'Controller Client',
        'awb_number' => '1234567890',
        'destination' => 'DLA',
        'amount' => '14000',
        'pieces' => '2',
        'weight_kg' => '1.25',
        'collection_time' => '10:15',
        'checked_by' => 'Spoofed Administrator',
    ]],
]));
$assert($adminEdit->status() === 200 && str_contains($adminEdit->body(), 'Records Administrator · admin'), 'An administrator should open the audited record editor with the account name.');
$assert($adminUpdate->status() === 303, 'An administrator should save an audited pickup-sheet correction.');
$adminUpdatedSheet = (new PickupSheetService(new DemoPickupSheetRepository()))->findByReference($savedReference);
$assert($adminUpdatedSheet?->totalCashReceivedXaf === 14000 && $adminUpdatedSheet->isPaid(), 'An administrator edit should persist while retaining paid status.');
$assert(($adminUpdatedSheet->shipments[0]->checkedBy ?? '') === 'Records Administrator', 'An administrator edit should stamp Check By with the administrator account name.');
$adminDashboard = $pickupController->dashboard(new Request('GET', '/dhl/pickupsheet/dashboard'));
$assert($adminDashboard->status() === 200, 'An administrator should open the KPI dashboard.');
$assert(str_contains($adminDashboard->body(), '14,000'), 'The KPI dashboard should reflect the administrator-corrected cash activity.');
$assert(str_contains($adminDashboard->body(), 'pickup-activity-chart'), 'The dashboard should render its activity graph without client-side chart dependencies.');
$adminUsers = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users', [], [], '', $recordsServer));
$assert($adminUsers->status() === 200, 'An administrator should open records-user management.');
$assert(str_contains($adminUsers->body(), 'Create local account'), 'The administrator should receive a local account-creation form.');
$assert(str_contains($adminUsers->body(), 'Reset my admin password'), 'The administrator should receive a current-password-confirmed password reset form.');
$assert(str_contains($adminUsers->body(), 'Email or username') && str_contains($adminUsers->body(), 'Account status'), 'Account management should expose flexible login IDs and explicit account status.');
$assert(str_contains($adminUsers->body(), 'name="first_name"') && str_contains($adminUsers->body(), 'name="last_name"'), 'Account management should require first and last names.');

$invalidUserCsrf = $pickupController->createUser(new Request('POST', '/dhl/pickupsheet/submissions/users', [], [
    '_token' => 'invalid-token',
    'username' => 'managed-operator',
    'role' => 'operator',
    'password' => 'managed-password-123',
    'password_confirmation' => 'managed-password-123',
], '', $recordsServer));
$assert($invalidUserCsrf->status() === 419, 'Account creation should require a valid CSRF token.');

$createManagedUser = $pickupController->createUser(new Request('POST', '/dhl/pickupsheet/submissions/users', [], [
    '_token' => $pickupCsrf->token(),
    'username' => 'managed-operator',
    'first_name' => 'Marc',
    'last_name' => 'Operator',
    'role' => 'operator',
    'active' => '1',
    'password' => 'managed-password-123',
    'password_confirmation' => 'managed-password-123',
], '', $recordsServer));
$assert($createManagedUser->status() === 303, 'An administrator should be able to create a lower-tier account.');
$managedAccount = $recordsUserRepository->all()[0] ?? null;
$assert($managedAccount?->role === 'operator' && $managedAccount->active && $managedAccount->fullName() === 'Marc Operator', 'A created lower-tier account should persist required names, role, and active status.');
$adminUsersWithAccount = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users', [], [], '', $recordsServer));
$assert(str_contains($adminUsersWithAccount->body(), 'managed-operator'), 'The management page should list a created lower-tier account.');
$usersView = file_get_contents(dirname(__DIR__) . '/views/pickupsheet/users.php');
$assert(is_string($usersView) && !str_contains($usersView, 'passwordHash'), 'The management view must never access or render a stored password hash.');
$managedPrincipal = $recordsAccess->authenticateCredentials('managed-operator', 'managed-password-123');
$assert($managedPrincipal?->role === 'operator' && $managedPrincipal->fullName() === 'Marc Operator', 'A newly created managed operator should authenticate with its account name.');
$recordsSession->login($managedPrincipal);
$managedExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => $savedReference]));
$assert($managedExport->status() === 200, 'A newly created operator should authenticate and export records.');

$recordsSession->login($adminPrincipal);
$promoteManagedAdmin = $pickupController->updateUser(new Request('POST', '/dhl/pickupsheet/submissions/users/update', [], [
    '_token' => $pickupCsrf->token(),
    'id' => (string) $managedAccount->id,
    'username' => 'managed-operator',
    'first_name' => 'Marc',
    'last_name' => 'Operator',
    'role' => 'admin',
    'active' => '1',
    'password' => '',
    'password_confirmation' => '',
], '', $recordsServer));
$assert($promoteManagedAdmin->status() === 303, 'A rejected hierarchy change should return to account management.');
$assert($recordsUserRepository->findById($managedAccount->id)?->role === 'operator', 'A managed account must never be promoted to administrator.');

$demoteManagedViewer = $pickupController->updateUser(new Request('POST', '/dhl/pickupsheet/submissions/users/update', [], [
    '_token' => $pickupCsrf->token(),
    'id' => (string) $managedAccount->id,
    'username' => 'managed-viewer',
    'first_name' => 'Marie',
    'last_name' => 'Viewer',
    'role' => 'viewer',
    'active' => '1',
    'password' => 'new-managed-password-456',
    'password_confirmation' => 'new-managed-password-456',
], '', $recordsServer));
$assert($demoteManagedViewer->status() === 303, 'An administrator should adjust a lower-tier username, role, and password.');
$assert($recordsAccess->authenticateCredentials('managed-viewer', 'managed-password-123') === null, 'A rotated managed password should invalidate the previous password immediately.');
$managedViewerPrincipal = $recordsAccess->authenticateCredentials('managed-viewer', 'new-managed-password-456');
$assert($managedViewerPrincipal?->role === 'viewer' && $managedViewerPrincipal->fullName() === 'Marie Viewer', 'The updated managed account should authenticate with its new viewer role and name.');
$recordsSession->login($managedViewerPrincipal);
$managedViewerExport = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => $savedReference]));
$assert($managedViewerExport->status() === 403, 'Role changes should take effect on the next request.');
$staleManagedIdentity = $_SESSION['_pickupsheet_identity'] ?? null;

$recordsSession->login($adminPrincipal);
$disableManagedViewer = $pickupController->updateUser(new Request('POST', '/dhl/pickupsheet/submissions/users/update', [], [
    '_token' => $pickupCsrf->token(),
    'id' => (string) $managedAccount->id,
    'username' => 'managed-viewer',
    'first_name' => 'Marie',
    'last_name' => 'Viewer',
    'role' => 'viewer',
    'active' => '0',
    'password' => '',
    'password_confirmation' => '',
], '', $recordsServer));
$assert($disableManagedViewer->status() === 303, 'An administrator should be able to disable a lower-tier account.');
$assert($recordsAccess->authenticateCredentials('managed-viewer', 'new-managed-password-456') === null, 'A disabled managed account should immediately reject new logins.');
$inactiveAccountPage = $pickupController->users(new Request('GET', '/dhl/pickupsheet/submissions/users'));
$assert(str_contains($inactiveAccountPage->body(), '>Inactive</span>') && str_contains($inactiveAccountPage->body(), '<option value="0" selected>Inactive</option>'), 'The admin area should clearly display and select an inactive account status.');
$_SESSION['_pickupsheet_identity'] = $staleManagedIdentity;
$disabledManagedList = $pickupController->submissions(new Request('GET', '/dhl/pickupsheet/submissions'));
$assert($disabledManagedList->status() === 302, 'Disabling a managed account should invalidate its existing portal session immediately.');

$recordsSession->login($adminPrincipal);
$printResponse = $pickupController->print(new Request('GET', '/dhl/pickupsheet/submissions/print', ['reference' => $savedReference], [], '', $recordsServer));
$printStyles = file_get_contents(dirname(__DIR__) . '/public/assets/print.css');
$printScript = file_get_contents(dirname(__DIR__) . '/public/assets/print.js');
$assert($printResponse->status() === 200, 'A direct pickup sheet should render for printing.');
$assert(str_contains($printResponse->body(), 'print.css?v=20260825-print-dialog'), 'The print view should load its CSP-compatible external stylesheet.');
$assert(str_contains($printResponse->body(), 'print.js?v=20260825-print-dialog'), 'The print view should load its CSP-compatible external behavior.');
$assert(str_contains($printResponse->body(), 'data-print-pickup'), 'The print view should provide a manual print-dialog trigger.');
$assert(!str_contains($printResponse->body(), 'onclick='), 'The print view should not rely on CSP-blocked inline event handlers.');
$assert(!str_contains($printResponse->body(), '<style>'), 'The print view should not rely on CSP-blocked inline styles.');
$assert(is_string($printScript) && str_contains($printScript, "document.addEventListener('DOMContentLoaded', autoPrint"), 'The print view should automatically trigger printing without waiting on the asynchronous Google tag.');
$assert(is_string($printScript) && str_contains($printScript, 'window.print()'), 'The external print behavior should open the browser print dialog.');
$assert(str_contains($printResponse->body(), $savedReference), 'The printable sheet should display its reference number.');
$assert(str_contains($printResponse->body(), 'PICK-UP SHEET'), 'The printable sheet should reproduce the original uppercase form heading.');
$assert(str_contains($printResponse->body(), 'CONTROLLER AGENT'), 'The printable sheet should uppercase the agent value in its generated content.');
$assert(str_contains($printResponse->body(), 'CONTROLLER CLIENT'), 'The printable sheet should uppercase consignor values in its generated content.');
$assert(str_contains($printResponse->body(), 'RECORDS ADMINISTRATOR'), 'The printable sheet should uppercase the authenticated checker name in its generated content.');
$assert(str_contains($printResponse->body(), '29/07/2026'), 'The printable sheet should use the original day/month/year date format.');
$assert(str_contains($printResponse->body(), 'paper-empty-row'), 'The printable sheet should retain blank continuation rows like the original form.');
$assert(is_string($printStyles) && str_contains($printStyles, '@page { size: A4 portrait;'), 'The printable sheet should use the original portrait page orientation.');
$assert(str_contains($printResponse->body(), 'class="print-preview"'), 'The A4 document should render inside a dedicated centered preview stage.');
$assert(is_string($printStyles) && str_contains($printStyles, 'place-items: start center;'), 'The A4 paper should be centered in its screen preview.');
$assert(is_string($printStyles) && str_contains($printStyles, 'min-width: 210mm;'), 'The on-screen preview should retain the exact A4 paper width.');
$assert(is_string($printStyles) && str_contains($printStyles, 'background: #5b5b5b;'), 'The on-screen A4 preview should use a clearly gray surround.');
$assert(is_string($printStyles) && str_contains($printStyles, 'background: #fff !important;'), 'The A4 paper should remain solid white on screen and in print output.');
$assert(is_string($printStyles) && str_contains($printStyles, 'width: 190mm;'), 'The printed document should use the exact A4 content width after equal page margins.');
$assert(is_string($printStyles) && str_contains($printStyles, 'margin: 0 auto !important;'), 'The printed document should be explicitly centered between the A4 margins.');
$assert(is_string($printStyles) && str_contains($printStyles, 'border: 1pt solid #000 !important;'), 'Every shipment cell should retain a solid black line when printed.');
$assert(is_string($printStyles) && str_contains($printStyles, 'text-align: left !important;'), 'Shipment headers and data should be explicitly left-aligned in the PDF.');
$assert(is_string($printStyles) && str_contains($printStyles, 'padding-left: 5px;'), 'Printable shipment cells should have five pixels of left padding.');
$assert(str_contains($printResponse->body(), 'border="1" rules="all" cellspacing="0"'), 'The shipment table should include a renderer-safe solid-grid fallback.');
$assert(is_string($printStyles) && !str_contains($printStyles, 'A4 landscape'), 'The old landscape print layout should be removed.');
$assert(!str_contains($printResponse->body(), 'dhl-logo.svg'), 'The printable sheet should not display the DHL logo.');
$assert(substr_count($printResponse->body(), 'https://www.googletagmanager.com/gtag/js?id=G-WVFXFB5H3M') === 1, 'The print page should contain exactly one Google tag.');
$assert(str_contains($printResponse->body(), 'data-analytics-page-view="disabled"'), 'Printable records should suppress Analytics page views and reference-query collection.');
$exportResponse = $pickupController->export(new Request('GET', '/dhl/pickupsheet/submissions/export', ['reference' => $savedReference], [], '', $recordsServer));
$assert($exportResponse->status() === 200, 'A direct pickup sheet should export successfully.');
$assert(str_starts_with($exportResponse->body(), "PK\x03\x04"), 'The Excel export should be a native XLSX ZIP package.');
$assert(substr($exportResponse->body(), -22, 4) === "PK\x05\x06", 'The native XLSX package should have a valid central-directory terminator.');
$assert(($exportResponse->headers()['Content-Type'] ?? '') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'The shipment workbook should use the native XLSX media type.');
$assert(str_contains($exportResponse->body(), '<c r="A1" s="1" t="inlineStr"><is><t>Consignor</t></is></c>'), 'The spreadsheet should begin directly with the consignor heading.');
$assert(!str_contains($exportResponse->body(), '<t>#</t>'), 'The spreadsheet should exclude the sequential row-number column.');
$assert(str_contains($exportResponse->body(), 'Controller Client'), 'The spreadsheet export should contain shipment data.');
$assert(!str_contains($exportResponse->body(), 'Reference number'), 'The spreadsheet should exclude pickup-sheet reference metadata.');
$assert(!str_contains($exportResponse->body(), 'Agent name'), 'The spreadsheet should exclude pickup-sheet agent metadata.');
$assert(!str_contains($exportResponse->body(), 'Collection date'), 'The spreadsheet should exclude pickup-sheet date metadata.');
$assert(!str_contains($exportResponse->body(), 'Shipments collected'), 'The spreadsheet should exclude pickup-sheet count metadata.');
$assert(!str_contains($exportResponse->body(), 'Total cash received'), 'The spreadsheet should exclude pickup-sheet header-total metadata.');
$assert(str_contains($exportResponse->body(), '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'), 'The native workbook should define bold characters for totals.');
$assert(str_contains($exportResponse->body(), 'SHIPMENT TOTAL'), 'The spreadsheet should include a bold shipment-total row.');
$assert(str_contains($exportResponse->body(), '<v>14000</v>'), 'The bold total row should contain the administrator-corrected shipment amount.');
$assert(($exportResponse->headers()['Content-Disposition'] ?? '') === 'attachment; filename="' . $savedReference . '.xlsx"', 'The Excel export should use a native XLSX filename.');
$xlsxPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttechcg-xlsx-' . bin2hex(random_bytes(8)) . '.zip';
$assert(file_put_contents($xlsxPath, $exportResponse->body()) !== false, 'The XLSX test fixture should be writable.');
try {
    $xlsxArchive = new PharData($xlsxPath, 0, null, Phar::ZIP);
    $assert(isset($xlsxArchive['[Content_Types].xml']), 'The XLSX archive should declare its package content types.');
    $assert(isset($xlsxArchive['xl/workbook.xml']), 'The XLSX archive should contain a workbook definition.');
    $assert(isset($xlsxArchive['xl/worksheets/sheet1.xml']), 'The XLSX archive should contain its cash-shipment worksheet.');
} finally {
    if (is_file($xlsxPath)) {
        unlink($xlsxPath);
    }
}

$invalidDeleteCsrf = $pickupController->deletePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/delete', [], [
    '_token' => 'invalid-token',
    'reference' => $savedReference,
]));
$assert($invalidDeleteCsrf->status() === 419, 'Deleting a pickup sheet should require a valid CSRF token.');
$deleteByAdmin = $pickupController->deletePickupSheet(new Request('POST', '/dhl/pickupsheet/submissions/delete', [], [
    '_token' => $pickupCsrf->token(),
    'reference' => $savedReference,
]));
$assert($deleteByAdmin->status() === 303, 'An administrator should delete a pickup sheet from active records.');
$assert((new PickupSheetService(new DemoPickupSheetRepository()))->findByReference($savedReference) === null, 'A deleted pickup sheet should no longer be returned in active records.');

$recordsSession->login($adminPrincipal);
$invalidAdminPasswordReset = $pickupController->resetAdminPassword(new Request('POST', '/dhl/pickupsheet/submissions/users/admin-password', [], [
    '_token' => $pickupCsrf->token(),
    'current_password' => 'wrong-admin-password',
    'password' => 'new-admin-password-789',
    'password_confirmation' => 'new-admin-password-789',
]));
$assert($invalidAdminPasswordReset->status() === 303, 'An incorrect current password should return to administrator security settings.');
$assert($recordsAccess->authenticateCredentials($recordsUsername, $recordsPassword)?->role === 'admin', 'A failed administrator reset must leave the current password unchanged.');
$resetAdminPassword = $pickupController->resetAdminPassword(new Request('POST', '/dhl/pickupsheet/submissions/users/admin-password', [], [
    '_token' => $pickupCsrf->token(),
    'current_password' => $recordsPassword,
    'password' => 'new-admin-password-789',
    'password_confirmation' => 'new-admin-password-789',
]));
$assert($resetAdminPassword->status() === 303 && ($resetAdminPassword->headers()['Location'] ?? '') === '/dhl/pickupsheet/login', 'A successful administrator password reset should sign out and return to login.');
$assert($recordsAccess->authenticateCredentials($recordsUsername, $recordsPassword) === null, 'The previous administrator password should stop working immediately.');
$newAdminPrincipal = $recordsAccess->authenticateCredentials($recordsUsername, 'new-admin-password-789');
$assert($newAdminPrincipal?->role === 'admin', 'The reset administrator password should authenticate through the portal.');
$passwordResetLoginPage = $pickupAuthController->login(new Request('GET', '/dhl/pickupsheet/login'));
$assert(str_contains($passwordResetLoginPage->body(), 'Administrator password reset'), 'The login portal should confirm a successful administrator password reset.');

$xlsxWriter = new XlsxWriter();
$escapedWorkbook = $xlsxWriter->create(['Name', 'Amount'], [['A&B <Logistics>', 2500]], 'SHIPMENT TOTAL', 2, 2500);
$assert(str_contains($escapedWorkbook, 'A&amp;B &lt;Logistics&gt;'), 'The XLSX writer should XML-escape untrusted shipment text.');
$assert(!str_contains($escapedWorkbook, '<f>'), 'Shipment strings should never be emitted as spreadsheet formulas.');

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
$assert(!str_contains($home, 'href="/dhl/pickupsheet"'), 'Pickupsheet should not be discoverable from the public site chrome or homepage.');
$assert(str_contains($home, 'styles.css?v=20260827-jumpcloud-rbac'), 'The JumpCloud RBAC update should use a cache-safe stylesheet version.');
$assert(str_contains($home, 'app.js?v=20260827-jumpcloud-rbac'), 'The JumpCloud RBAC update should use a cache-safe application script version.');
$assert(str_contains($home, 'analytics.js?v=20260825-security-hardening'), 'The current consent-aware Google Analytics loader should render on every page.');
$assert(str_contains($home, 'data-analytics-accept'), 'The site should offer an explicit analytics acceptance control.');
$assert(str_contains($home, 'data-analytics-decline'), 'The site should offer an explicit analytics decline control.');
$assert(str_contains($home, 'data-analytics-settings'), 'Visitors should be able to reopen analytics settings from the footer.');
$assert((bool) preg_match('/<head>\s*<script async src="https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=G-WVFXFB5H3M"><\/script>/', $home), 'The supplied Google tag should appear immediately after the head element.');
$assert(substr_count($home, 'https://www.googletagmanager.com/gtag/js?id=G-WVFXFB5H3M') === 1, 'Each standard page should contain exactly one Google tag.');
$assert(str_contains($home, 'google-tag.js?v=20260825-security-hardening'), 'Each page should load the CSP-compatible Google tag initializer once.');
$assert(str_contains($home, 'data-analytics-page-view="enabled"'), 'Public corporate pages should permit consent-aware Analytics page views.');
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
$assert(str_contains($home, 'class="site-header"') && str_contains($home, 'class="site-footer"'), 'Public corporate pages should retain the company header and footer.');
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
$assert(!str_contains($products, 'href="/dhl/pickupsheet"'), 'The direct Pickupsheet route should not be listed in the product catalogue.');

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
    'recordsUsername' => 'records-viewer',
    'recordsRole' => 'viewer',
]));
$assert(str_contains($product, 'pickupsheet'), 'The Pickupsheet product page should render.');
$assert(!str_contains($product, 'dhl-logo.svg'), 'The Pickupsheet product page should use a text-only heading.');
$assert(str_contains($product, 'Cash shipments'), 'The PDF cash-shipment section should render.');
$assert(str_contains($product, 'name="agent_name"'), 'The pickup form should collect the agent name.');
$assert(str_contains($product, 'name="collection_date"'), 'The pickup form should collect the sheet date.');
$assert(str_contains($product, 'Reference number'), 'The pickup form should disclose its automatic reference number.');
$assert(str_contains($product, 'Assigned when saved'), 'Operators should know when the reference number is generated.');
$assert(str_contains($product, 'href="/dhl/pickupsheet/submissions"'), 'The direct entry screen should link to the submissions table.');
$assert(str_contains($product, 'action="/dhl/pickupsheet"'), 'The pickup form should submit to its new DHL-namespaced route.');
$assert(str_contains($product, 'shipments[0][consignor]'), 'The pickup form should collect a consignor for each row.');
$assert(str_contains($product, 'shipments[0][awb_number]'), 'The pickup form should collect the AWB number from the PDF.');
$assert(str_contains($product, 'shipments[0][destination]'), 'The pickup form should collect the destination code from the PDF.');
$assert(str_contains($product, 'shipments[0][amount]'), 'The pickup form should collect cash amounts from the PDF.');
$assert(str_contains($product, 'shipments[0][pieces]'), 'The pickup form should collect piece counts from the PDF.');
$assert(str_contains($product, 'shipments[0][weight_kg]'), 'The pickup form should collect shipment weight from the PDF.');
$assert(!str_contains($product, 'shipments[0][collection_time]'), 'The pickup form should not allow operators to alter the collection time.');
$assert(str_contains($product, 'Time collected is recorded automatically when this pickup sheet is submitted.'), 'The pickup form should explain its server-recorded submission time.');
$assert(str_contains($product, 'shipments[0][checked_by]'), 'The pickup form should submit its account-controlled checker identity.');
$assert(str_contains($product, 'data-identity-field') && str_contains($product, 'readonly aria-readonly="true"'), 'The checker field should be populated and locked to the signed-in account name.');
$assert(str_contains($product, 'action="/dhl/pickupsheet/logout"'), 'The protected workspace should provide a CSRF-protected logout action.');
$assert(str_contains($product, 'records-viewer'), 'The protected workspace should identify the signed-in user.');
$assert(str_contains($product, 'data-shipment-count'), 'The pickup form should calculate shipments collected.');
$assert(str_contains($product, 'data-shipment-total'), 'The pickup form should calculate total cash received.');
$assert(str_contains($product, 'name="captcha_nonce" value="pickup-captcha-nonce"'), 'The pickup form should include first-party human verification.');
$assert(str_contains($product, 'type="checkbox" name="privacy_consent" value="1" required'), 'The pickup form should require explicit privacy consent.');
$assert(!str_contains($product, 'name="privacy_consent" value="1" required checked'), 'Pickup-sheet consent should not be preselected.');
$assert(str_contains($product, '<meta name="robots" content="noindex, nofollow">'), 'The direct Pickupsheet page should not be indexed.');
$assert(str_contains($product, 'data-analytics-page-view="disabled"'), 'Pickup-sheet operational pages should suppress Analytics page views.');
$sitemap = file_get_contents(dirname(__DIR__) . '/sitemap.xml');
$assert(is_string($sitemap) && !str_contains($sitemap, '/dhl/pickupsheet'), 'The sitemap should not advertise the new Pickupsheet route.');
$assert(is_string($sitemap) && !str_contains($sitemap, '/pickupsheet'), 'The sitemap should not advertise the legacy Pickupsheet route.');
$assert(is_string($sitemap) && str_contains($sitemap, '/products'), 'The sitemap should advertise the public product catalogue.');

$privacy = $view->render('site/privacy', array_merge($common, [
    'pageTitle' => 'Privacy notice',
    'pageDescription' => 'Test description',
    'activePage' => 'privacy',
]));
$assert(str_contains($privacy, 'Information we collect'), 'The privacy notice should explain collected information.');
$assert(str_contains($privacy, 'agent name, collection date, consignor, AWB number'), 'The privacy notice should disclose pickup-sheet fields.');
$assert(str_contains($privacy, 'record and reconcile cash shipment collections'), 'The privacy notice should state the pickup-sheet processing purpose.');
$assert(str_contains($privacy, 'checker identity associated with the authenticated account'), 'The privacy notice should explain the account-controlled checker identity.');
$assert(str_contains($privacy, 'first name, last name, email address or username'), 'The privacy notice should disclose stored account identity fields.');
$assert(str_contains($privacy, 'records the collection time automatically'), 'The privacy notice should explain the server-generated collection time.');
$assert(str_contains($privacy, 'Every Pickupsheet screen requires an authorised staff login'), 'The privacy notice should disclose portal authentication.');
$assert(str_contains($privacy, 'Operators and administrators can edit records and change an open sheet to paid'), 'The privacy notice should disclose the record-edit and payment-status boundary.');
$assert(str_contains($privacy, 'audited soft deletion'), 'The privacy notice should disclose administrator-only soft deletion.');
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
$assert(str_contains($privacy, 'Declining keeps analytics storage denied'), 'The privacy notice should explain the effect of declining analytics.');
$assert(str_contains($privacy, 'cookieless consent-state pings'), 'The privacy notice should disclose pre-consent Consent Mode pings.');
$assert(str_contains($privacy, 'Analytics page views are suppressed on pickup-sheet operational pages'), 'The privacy notice should disclose sensitive-route Analytics suppression.');
$assert(str_contains($privacy, 'Cookie settings'), 'The privacy notice should explain how to change the analytics choice.');
$assert(str_contains($privacy, 'one-way password hash'), 'The privacy notice should disclose managed staff account data without implying plaintext password storage.');
$assert(str_contains($privacy, 'JumpCloud single sign-on') && str_contains($privacy, 'group membership'), 'The privacy notice should disclose JumpCloud identity and role processing.');
$assert(str_contains($privacy, 'Access and ID tokens are used only to complete sign-in and are not retained'), 'The privacy notice should state the OIDC token-retention boundary.');

$environmentExample = file_get_contents(dirname(__DIR__) . '/.env.example');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'APP_TIMEZONE=Africa/Douala'), 'The environment example should use Cameroon time.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'CONTACT_EMAIL=info@ttechcg.com'), 'The environment example should route production inquiries to the company mailbox.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'PICKUPSHEET_RBAC_USERS=records-admin|admin|Records|Administrator|replace-with-password-hash'), 'The environment example should configure role-based records users with names and server-managed password hashes.');
$credentialGenerator = file_get_contents(dirname(__DIR__) . '/bin/generate-records-credentials.php');
$assert(is_string($credentialGenerator) && str_contains($credentialGenerator, "['viewer', 'operator', 'admin']"), 'The credential generator should restrict accounts to defined RBAC roles.');
$assert(is_string($credentialGenerator) && str_contains($credentialGenerator, "PICKUPSHEET_RBAC_USERS='"), 'The credential generator should provide a cPanel-ready RBAC environment value.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'RUN_MIGRATIONS=true'), 'The environment example should explicitly document migration execution.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'JUMPCLOUD_OIDC_ENABLED=false'), 'The environment example should keep JumpCloud disabled until credentials are supplied.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'JUMPCLOUD_OIDC_REDIRECT_URI=https://ttechcg.com/dhl/pickupsheet/auth/jumpcloud/callback'), 'The environment example should document the exact JumpCloud callback URI.');
$assert(is_string($environmentExample) && str_contains($environmentExample, 'JUMPCLOUD_RBAC_ADMIN_GROUP=Pickupsheet Admins'), 'The environment example should map JumpCloud groups to Pickupsheet roles.');
$assert(is_string($environmentExample) && !str_contains($environmentExample, 'JUMPCLOUD_OIDC_LOCAL_LOGIN'), 'Local account login should not have a configuration switch that JumpCloud can disable.');

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
$assert(is_string($styles) && str_contains($styles, '.pickup-workspace') && str_contains($styles, 'background: var(--dhl-yellow);'), 'The pickup-sheet page should retain its DHL-yellow background.');
$assert(is_string($styles) && str_contains($styles, 'background: #ffffff;'), 'The pickup-sheet data-entry card should use a white form area.');
$assert(is_string($styles) && str_contains($styles, '.shipment-row'), 'Shipment rows should have responsive form styling.');
$assert(is_string($styles) && str_contains($styles, 'content: attr(data-label);'), 'Shipment rows should expose their field labels in the mobile card layout.');
$assert(is_string($styles) && str_contains($styles, '/* Pickup-sheet records */'), 'Submitted pickup sheets should have a dedicated table layout.');
$assert(is_string($styles) && str_contains($styles, '.pickup-record-actions'), 'Each submitted sheet should style its print and spreadsheet actions.');
$assert(is_string($styles) && str_contains($styles, '.pickup-record-actions > form { flex: 0 0 116px; }'), 'Submitted-sheet links and form actions should share one consistent width.');
$assert(is_string($styles) && str_contains($styles, 'height: 42px;'), 'Submitted-sheet actions should share one consistent height.');
$assert(is_string($styles) && str_contains($styles, '.pickup-records-loading'), 'AJAX pagination should have a visible loading overlay.');
$assert(is_string($styles) && str_contains($styles, '@keyframes pickup-records-spin'), 'The loading overlay should provide spinner animation.');
$assert(is_string($styles) && str_contains($styles, '.pickup-pagination'), 'Submitted sheets should provide responsive pagination controls.');
$assert(is_string($styles) && str_contains($styles, '.records-users-layout'), 'Administrator account management should have a dedicated responsive layout.');
$assert(is_string($styles) && str_contains($styles, '.records-user-card'), 'Managed accounts should render as editable account cards.');
$assert(is_string($styles) && str_contains($styles, '/* Pickupsheet login portal */'), 'Pickupsheet should have a dedicated responsive login portal.');
$assert(is_string($styles) && str_contains($styles, '.pickup-admin-workspace') && str_contains($styles, '.pickup-kpi-grid'), 'The administrator should have a dedicated KPI control-panel layout.');
$assert(is_string($styles) && str_contains($styles, '.shipment-editor > *') && str_contains($styles, 'max-width: 1180px;'), 'The cash-shipment editor should be centered within the available screen width.');
$assert(is_string($styles) && str_contains($styles, '.shipment-editor-heading > div { grid-column: 2; text-align: center; }'), 'The Cash Shipments heading should remain visually centered beside its row action.');
$assert(is_string($styles) && str_contains($styles, '.shipment-table {') && str_contains($styles, 'min-width: 0;'), 'Shipment tables should shrink to the available desktop width instead of forcing horizontal overflow.');
$assert(is_string($styles) && str_contains($styles, '.shipment-table-edit th:nth-child(10) { width: 8%; }'), 'Editable shipment columns should use a proportional fit-to-screen layout.');

$script = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$assert(is_string($script) && str_contains($script, "event.key === 'Escape'"), 'The mobile navigation should close with Escape.');
$assert(is_string($script) && str_contains($script, "toggleAttribute('inert', open)"), 'The open mobile navigation should isolate background content.');
$assert(is_string($script) && str_contains($script, "matchMedia('(min-width: 821px)')"), 'The navigation state should reset when returning to desktop width.');
$assert(is_string($script) && str_contains($script, "document.querySelector('[data-pickup-form]')"), 'The pickup form should initialize its dynamic row editor.');
$assert(is_string($script) && str_contains($script, 'maximumRows = 50'), 'The browser should enforce the server shipment-row limit.');
$assert(is_string($script) && str_contains($script, 'numberFormatter.format(total)'), 'The browser should calculate and format cash totals.');
$assert(is_string($script) && str_contains($script, "[data-field]:not([data-identity-field])"), 'Account-populated checker fields should not make an otherwise blank shipment count as complete.');
$assert(is_string($script) && str_contains($script, "document.querySelector('[data-pickup-records]')"), 'The browser should initialize submitted-sheet pagination.');
$assert(is_string($script) && str_contains($script, 'await fetch(pageEndpoint'), 'Pagination should load records asynchronously.');
$assert(is_string($script) && str_contains($script, "spinner.hidden = !loading"), 'AJAX pagination should toggle its loading spinner.');
$assert(is_string($script) && str_contains($script, "window.history.pushState"), 'AJAX pagination should preserve browser history.');

$analyticsScript = file_get_contents(dirname(__DIR__) . '/public/assets/analytics.js');
$googleTagScript = file_get_contents(dirname(__DIR__) . '/public/assets/google-tag.js');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, "window.gtag('config', 'G-WVFXFB5H3M')"), 'The supplied Google Analytics measurement ID should be configured exactly once.');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, "window.gtag('js', new Date())"), 'The supplied Google tag should initialize gtag.js.');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, "analytics_storage: 'denied'"), 'Analytics storage should be denied by default.');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, "ad_storage: 'denied'"), 'Advertising storage should remain denied.');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, 'send_page_view: false'), 'Sensitive pickup-sheet routes should suppress Analytics page views.');
$assert(is_string($googleTagScript) && str_contains($googleTagScript, 'window.location.origin}${window.location.pathname}'), 'Sensitive Analytics configuration should remove record-reference query values.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "analytics_storage: 'granted'"), 'The consent controller should grant analytics storage only after acceptance.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, 'analyticsSuppressed'), 'Consent acceptance should not re-enable Analytics on sensitive routes.');
$assert(is_string($analyticsScript) && str_contains($analyticsScript, "preference === 'granted'"), 'A saved grant should restore accepted analytics consent.');
$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$assert(is_string($htaccess) && str_contains($htaccess, "script-src 'self' https://www.googletagmanager.com"), 'The CSP should permit the supplied Google tag script after consent.');
$assert(is_string($htaccess) && str_contains($htaccess, "connect-src 'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com"), 'The CSP should permit Google Analytics measurement requests after consent.');
$assert(is_string($htaccess) && !str_contains($htaccess, "script-src 'self' 'unsafe-inline'"), 'The Google tag integration should not weaken CSP with inline-script permission.');
$assert(is_string($htaccess) && str_contains($htaccess, "base-uri 'none'"), 'The CSP should disallow document base URL changes.');
$assert(is_string($htaccess) && str_contains($htaccess, 'Strict-Transport-Security "max-age=31536000"'), 'Production should enforce HTTPS with one year of HSTS.');
$assert(is_string($htaccess) && str_contains($htaccess, 'Header always unset X-Powered-By'), 'Production should suppress backend version disclosure.');
$assert(is_string($htaccess) && str_contains($htaccess, 'Cross-Origin-Opener-Policy "same-origin"'), 'Production documents should receive cross-origin opener isolation.');
$assert(is_string($htaccess) && str_contains($htaccess, 'https://ttechcg.com%{REQUEST_URI} [R=308,L,NE]'), 'Production should redirect the first request to canonical HTTPS before credentials can reach PHP.');
$assert(is_string($htaccess) && str_contains($htaccess, 'E=HTTP_AUTHORIZATION:%1'), 'Apache should forward HTTPS Basic credentials to PHP safely.');

$cpanelDeployment = file_get_contents(dirname(__DIR__) . '/.cpanel.yml');
$assert(is_string($cpanelDeployment) && str_contains($cpanelDeployment, 'chmod 700 ${DEPLOYPATH}storage/sessions ${DEPLOYPATH}storage/security'), 'Deployment should restrict writable runtime storage to the account owner.');
$assert(is_string($cpanelDeployment) && str_contains($cpanelDeployment, 'test -w ${DEPLOYPATH}storage/security'), 'Deployment should fail if persistent security storage is not writable.');

$verificationWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/verify.yml');
$assert(is_string($verificationWorkflow) && str_contains($verificationWorkflow, 'actions/checkout@11d5960a326750d5838078e36cf38b85af677262'), 'CI dependencies should be pinned to an immutable commit.');
$assert(is_string($verificationWorkflow) && !str_contains($verificationWorkflow, 'actions/checkout@v4'), 'CI should not execute a mutable action tag.');

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
$pickupReferenceMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/004_add_pickup_sheet_reference.sql');
$assert(is_string($pickupReferenceMigration) && str_contains($pickupReferenceMigration, 'reference_number VARCHAR(48)'), 'The database should store a pickup-sheet reference number.');
$assert(is_string($pickupReferenceMigration) && str_contains($pickupReferenceMigration, 'UNIQUE INDEX pickup_sheets_reference_idx'), 'Pickup-sheet reference numbers should be unique.');
$assert(is_string($pickupReferenceMigration) && str_contains($pickupReferenceMigration, '-LEGACY-'), 'Existing pickup sheets should receive a migration-safe reference.');
$pickupMysqlRepository = file_get_contents(dirname(__DIR__) . '/src/Modules/Pickupsheet/Infrastructure/MysqlPickupSheetRepository.php');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'beginTransaction()'), 'Pickup-sheet headers and rows should save transactionally.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, ':reference_number'), 'MySQL persistence should store the generated pickup-sheet reference.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, ':total_cash_received_xaf'), 'MySQL persistence should store the server-calculated XAF total.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'findByReference'), 'MySQL persistence should support reference-scoped print and export queries.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'LIMIT :limit OFFSET :offset'), 'MySQL should paginate pickup sheets at query time.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'SELECT COUNT(*) FROM pickup_sheets'), 'MySQL should count records for pagination metadata.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'pickup_sheet_edit_audit'), 'Operator record edits should persist before-and-after audit snapshots.');
$assert(is_string($pickupMysqlRepository) && str_contains($pickupMysqlRepository, 'FOR UPDATE'), 'Record edits should lock their pickup sheet during the audit transaction.');
$assert(str_contains($pickupMysqlRepository, "SET status = 'paid'"), 'MySQL should persist the open-to-paid lifecycle transition.');
$assert(str_contains($pickupMysqlRepository, 'SET deleted_at = UTC_TIMESTAMP()'), 'Administrator deletion should retain pickup sheets through a soft-delete timestamp.');
$assert(str_contains($pickupMysqlRepository, 'pickup_sheet_lifecycle_audit'), 'Paid and delete actions should persist lifecycle audit snapshots.');
$pickupEditMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/006_create_pickup_sheet_edit_audit.sql');
$assert(is_string($pickupEditMigration) && str_contains($pickupEditMigration, 'CREATE TABLE IF NOT EXISTS pickup_sheet_edit_audit'), 'MySQL should provide an idempotent pickup-sheet edit audit migration.');
$assert(is_string($pickupEditMigration) && str_contains($pickupEditMigration, 'before_snapshot LONGTEXT'), 'The edit audit should retain the prior record snapshot.');
$assert(is_string($pickupEditMigration) && str_contains($pickupEditMigration, 'after_snapshot LONGTEXT'), 'The edit audit should retain the corrected record snapshot.');
$pickupLifecycleMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/007_add_pickup_sheet_lifecycle.sql');
$assert(is_string($pickupLifecycleMigration) && str_contains($pickupLifecycleMigration, "status VARCHAR(20) NOT NULL DEFAULT 'open'"), 'Every persisted pickup sheet should default to open status.');
$assert(is_string($pickupLifecycleMigration) && str_contains($pickupLifecycleMigration, 'paid_at DATETIME NULL'), 'MySQL should retain when a pickup sheet was marked paid.');
$assert(is_string($pickupLifecycleMigration) && str_contains($pickupLifecycleMigration, 'deleted_at DATETIME NULL'), 'MySQL should retain audited soft deletion state.');
$recordsUserMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/005_create_pickup_records_users.sql');
$assert(is_string($recordsUserMigration) && str_contains($recordsUserMigration, 'CREATE TABLE IF NOT EXISTS pickup_records_users'), 'MySQL should persist managed records-user accounts.');
$assert(is_string($recordsUserMigration) && str_contains($recordsUserMigration, 'UNIQUE INDEX pickup_records_users_username_idx'), 'Managed records usernames should be unique.');
$assert(is_string($recordsUserMigration) && str_contains($recordsUserMigration, 'created_by CHAR(24)'), 'Managed account changes should retain a pseudonymous administrator audit identifier.');
$recordsUserNamesMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/009_add_pickup_records_user_names.sql');
$assert(is_string($recordsUserNamesMigration) && str_contains($recordsUserNamesMigration, 'first_name VARCHAR(49)') && str_contains($recordsUserNamesMigration, 'last_name VARCHAR(49)'), 'Managed users should receive required first and last name storage.');
$assert(is_string($recordsUserNamesMigration) && str_contains($recordsUserNamesMigration, 'MODIFY COLUMN first_name VARCHAR(49) NOT NULL'), 'The name migration should enforce required identity fields after backfilling existing users.');
$recordsUserMysqlRepository = file_get_contents(dirname(__DIR__) . '/src/Shared/Infrastructure/MysqlRecordsUserRepository.php');
$assert(is_string($recordsUserMysqlRepository) && str_contains($recordsUserMysqlRepository, 'BINARY username = :username AND active = 1'), 'Managed account authentication should require an exact username and active status.');
$assert(is_string($recordsUserMysqlRepository) && str_contains($recordsUserMysqlRepository, 'password_hash = :password_hash'), 'Administrators should be able to rotate managed account passwords.');
$assert(is_string($recordsUserMysqlRepository) && str_contains($recordsUserMysqlRepository, 'first_name = :first_name') && str_contains($recordsUserMysqlRepository, 'last_name = :last_name'), 'Administrators should be able to maintain required account names.');
$assert(is_string($recordsUserMysqlRepository) && str_contains($recordsUserMysqlRepository, 'private function ensureSchema()'), 'Administrator account management should initialize its idempotent schema without cPanel CLI access.');
$adminCredentialMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/008_create_pickup_records_admin_credentials.sql');
$assert(is_string($adminCredentialMigration) && str_contains($adminCredentialMigration, 'CREATE TABLE IF NOT EXISTS pickup_records_admin_credentials'), 'MySQL should persist administrator password overrides outside source-controlled environment configuration.');
$assert(str_contains($recordsUserMysqlRepository, 'saveAdminPasswordHash'), 'An administrator should be able to securely rotate the server-defined portal password.');
$findActiveMethod = substr($recordsUserMysqlRepository, 0, (int) strpos($recordsUserMysqlRepository, 'public function findById'));
$assert(!str_contains($findActiveMethod, '$this->ensureSchema();'), 'Anonymous or managed-user authentication must not trigger schema creation.');
$jumpCloudProviderSource = file_get_contents(dirname(__DIR__) . '/src/Shared/Security/JumpCloudOidcProvider.php');
$oidcHttpSource = file_get_contents(dirname(__DIR__) . '/src/Shared/Security/NativeOidcHttpClient.php');
$assert(is_string($jumpCloudProviderSource) && str_contains($jumpCloudProviderSource, "'code_challenge_method' => 'S256'"), 'JumpCloud authorization should use PKCE S256.');
$assert(is_string($jumpCloudProviderSource) && str_contains($jumpCloudProviderSource, 'openssl_verify('), 'JumpCloud ID tokens should be cryptographically verified.');
$assert(is_string($jumpCloudProviderSource) && str_contains($jumpCloudProviderSource, 'isset($claims[\'at_hash\'])'), 'JumpCloud access tokens should be bound to the ID token whenever an at_hash claim is issued.');
$assert(is_string($jumpCloudProviderSource) && str_contains($jumpCloudProviderSource, 'ALLOWED_ISSUERS'), 'JumpCloud regional issuer hosts should be allowlisted against SSRF and token confusion.');
$assert(is_string($jumpCloudProviderSource) && str_contains($jumpCloudProviderSource, "['admin', 'operator', 'viewer']"), 'JumpCloud group mapping should apply a fixed role hierarchy.');
$assert(is_string($oidcHttpSource) && str_contains($oidcHttpSource, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($oidcHttpSource, 'CURLOPT_FOLLOWLOCATION => false'), 'OIDC back-channel requests should verify TLS and reject redirects.');
$bootstrap = file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'httponly' => true"), 'The security session cookie should be inaccessible to client-side scripts.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'samesite' => 'Lax'"), 'The security session cookie should use a SameSite policy.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "session.use_strict_mode', '1'"), 'PHP should reject uninitialized attacker-selected session identifiers.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'__Host-ttechcg_session'"), 'The production session cookie should use the host-only secure prefix.');
$assert(is_string($bootstrap) && str_contains($bootstrap, '$connection !== null && $runMigrations'), 'Production migrations should require an explicit environment switch.');
$assert(is_string($bootstrap) && str_contains($bootstrap, 'RecordsAccess::fromEnvironment($recordsUserRepository)'), 'Stored pickup-sheet records should combine fail-closed environment admins with managed lower-tier accounts.');
$assert(is_string($bootstrap) && str_contains($bootstrap, 'JumpCloudOidcProvider::fromEnvironment()'), 'Pickupsheet should initialize the optional JumpCloud OIDC provider from server-managed configuration.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/auth/jumpcloud/callback'"), 'Pickupsheet should expose the exact JumpCloud OIDC callback route.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet'"), 'Pickupsheet should be routed under the DHL namespace.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/page'"), 'Pickupsheet should expose a protected AJAX pagination endpoint.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/users'"), 'Pickupsheet should expose administrator-only account management.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/dashboard'"), 'Pickupsheet should expose the administrator KPI control panel.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/edit'"), 'Pickupsheet should expose the operator-and-administrator audited record editor.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/paid'"), 'Pickupsheet should expose the protected open-to-paid action.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/delete'"), 'Pickupsheet should expose the administrator-only delete action.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/submissions/users/admin-password'"), 'Pickupsheet should expose administrator password rotation.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/pickupsheet'"), 'The legacy Pickupsheet entry should retain a permanent redirect.');
$assert(is_string($bootstrap) && str_contains($bootstrap, 'rawurlencode($reference)'), 'Legacy print and export redirects should preserve the reference query safely.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/login'"), 'Pickupsheet should expose its dedicated session login portal.');
$assert(is_string($bootstrap) && str_contains($bootstrap, "'/dhl/pickupsheet/logout'"), 'Pickupsheet should expose a CSRF-protected logout route.');

echo "All application tests passed.\n";
