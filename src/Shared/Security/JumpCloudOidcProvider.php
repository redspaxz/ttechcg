<?php

declare(strict_types=1);

namespace App\Shared\Security;

use JsonException;
use RuntimeException;

final class JumpCloudOidcProvider
{
    private const TRANSACTION_KEY = '_pickupsheet_jumpcloud_oidc';
    private const TRANSACTION_LIFETIME = 600;
    private const CLOCK_SKEW = 60;
    private const ALLOWED_ISSUERS = [
        'https://oauth.id.jumpcloud.com/',
        'https://oauth.id.eu.jumpcloud.com/',
        'https://oauth.id.in.jumpcloud.com/',
    ];

    private readonly bool $enabled;
    private readonly string $issuer;
    private readonly string $clientId;
    private readonly string $clientSecret;
    private readonly string $redirectUri;
    private readonly string $groupsClaim;
    private readonly string $clientAuthentication;
    /** @var array{admin: string, operator: string, viewer: string} */
    private readonly array $roleGroups;
    private readonly OidcHttpClient $httpClient;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings, ?OidcHttpClient $httpClient = null)
    {
        $this->enabled = (bool) ($settings['enabled'] ?? false);
        $this->issuer = rtrim(trim((string) ($settings['issuer'] ?? '')), '/') . '/';
        $this->clientId = trim((string) ($settings['client_id'] ?? ''));
        $this->clientSecret = trim((string) ($settings['client_secret'] ?? ''));
        $this->redirectUri = trim((string) ($settings['redirect_uri'] ?? ''));
        $this->groupsClaim = trim((string) ($settings['groups_claim'] ?? 'groups'));
        $this->clientAuthentication = trim((string) ($settings['client_authentication'] ?? 'client_secret_basic'));
        $this->roleGroups = [
            'admin' => trim((string) ($settings['admin_group'] ?? '')),
            'operator' => trim((string) ($settings['operator_group'] ?? '')),
            'viewer' => trim((string) ($settings['viewer_group'] ?? '')),
        ];
        $this->httpClient = $httpClient ?? new NativeOidcHttpClient();
    }

    public static function fromEnvironment(?OidcHttpClient $httpClient = null): self
    {
        return new self([
            'enabled' => filter_var(getenv('JUMPCLOUD_OIDC_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
            'issuer' => getenv('JUMPCLOUD_OIDC_ISSUER') ?: 'https://oauth.id.jumpcloud.com/',
            'client_id' => getenv('JUMPCLOUD_OIDC_CLIENT_ID') ?: '',
            'client_secret' => getenv('JUMPCLOUD_OIDC_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('JUMPCLOUD_OIDC_REDIRECT_URI') ?: '',
            'groups_claim' => getenv('JUMPCLOUD_OIDC_GROUPS_CLAIM') ?: 'groups',
            'client_authentication' => getenv('JUMPCLOUD_OIDC_CLIENT_AUTHENTICATION') ?: 'client_secret_basic',
            'admin_group' => getenv('JUMPCLOUD_RBAC_ADMIN_GROUP') ?: '',
            'operator_group' => getenv('JUMPCLOUD_RBAC_OPERATOR_GROUP') ?: '',
            'viewer_group' => getenv('JUMPCLOUD_RBAC_VIEWER_GROUP') ?: '',
        ], $httpClient);
    }

    public function isConfigured(): bool
    {
        if (!$this->enabled
            || !function_exists('curl_init')
            || !function_exists('openssl_verify')
            || !function_exists('openssl_pkey_get_public')
            || !in_array($this->issuer, self::ALLOWED_ISSUERS, true)
            || $this->clientId === ''
            || strlen($this->clientId) > 512
            || $this->clientSecret === ''
            || strlen($this->clientSecret) > 2048
            || !$this->validRedirectUri($this->redirectUri)
            || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/', $this->groupsClaim) !== 1
            || !in_array($this->clientAuthentication, ['client_secret_basic', 'client_secret_post'], true)) {
            return false;
        }

        $normalizedGroups = [];
        foreach ($this->roleGroups as $group) {
            if ($group === '' || strlen($group) > 128 || preg_match('/[\x00-\x1F\x7F]/', $group) === 1) {
                return false;
            }
            $normalizedGroups[] = strtolower($group);
        }

        return count(array_unique($normalizedGroups)) === 3;
    }

    /** @return array{admin: string, operator: string, viewer: string} */
    public function roleGroups(): array
    {
        return $this->roleGroups;
    }

    public function authorizationUrl(): string
    {
        $this->assertConfigured();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('A secure login session is unavailable.');
        }

        $state = self::base64UrlEncode(random_bytes(32));
        $nonce = self::base64UrlEncode(random_bytes(32));
        $verifier = self::base64UrlEncode(random_bytes(64));
        $_SESSION[self::TRANSACTION_KEY] = [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'created_at' => time(),
        ];

        return $this->endpoint('/oauth2/auth') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64UrlEncode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function authenticate(string $code, string $state): RecordsPrincipal
    {
        $this->assertConfigured();
        $transaction = $_SESSION[self::TRANSACTION_KEY] ?? null;
        unset($_SESSION[self::TRANSACTION_KEY]);

        if (!is_array($transaction)
            || !is_string($transaction['state'] ?? null)
            || !is_string($transaction['nonce'] ?? null)
            || !is_string($transaction['verifier'] ?? null)
            || !is_int($transaction['created_at'] ?? null)
            || $transaction['created_at'] < time() - self::TRANSACTION_LIFETIME
            || $state === ''
            || !hash_equals($transaction['state'], $state)
            || $code === ''
            || strlen($code) > 4096
            || preg_match('/[\x00-\x20\x7F]/', $code) === 1) {
            throw new RuntimeException('The JumpCloud login transaction is invalid or expired.');
        }

        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $transaction['verifier'],
        ];
        $headers = [];
        if ($this->clientAuthentication === 'client_secret_post') {
            $fields['client_id'] = $this->clientId;
            $fields['client_secret'] = $this->clientSecret;
        } else {
            $credentials = rawurlencode($this->clientId) . ':' . rawurlencode($this->clientSecret);
            $headers['Authorization'] = 'Basic ' . base64_encode($credentials);
        }

        $tokens = $this->httpClient->postForm($this->endpoint('/oauth2/token'), $fields, $headers);
        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $idToken = is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : '';
        $tokenType = strtolower((string) ($tokens['token_type'] ?? ''));
        if ($accessToken === ''
            || strlen($accessToken) > 16384
            || preg_match('/[\x00-\x20\x7F]/', $accessToken) === 1
            || $idToken === ''
            || strlen($idToken) > 65536
            || $tokenType !== 'bearer') {
            throw new RuntimeException('JumpCloud did not issue a valid identity token.');
        }

        $idClaims = $this->verifiedIdTokenClaims($idToken, $transaction['nonce'], $accessToken);
        $userInfo = $this->httpClient->getJson($this->endpoint('/userinfo'), [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $subject = is_string($idClaims['sub'] ?? null) ? $idClaims['sub'] : '';
        if (!is_string($userInfo['sub'] ?? null) || !hash_equals($subject, $userInfo['sub'])) {
            throw new RuntimeException('JumpCloud returned an inconsistent user identity.');
        }

        $claims = array_replace($idClaims, $userInfo);
        $role = $this->roleFromClaims($claims);
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $firstName = trim((string) ($claims['given_name'] ?? ''));
        $lastName = trim((string) ($claims['family_name'] ?? ''));
        $displayName = trim((string) ($claims['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim($firstName . ' ' . $lastName);
        }
        if ($role === null
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 100
            || ($claims['email_verified'] ?? null) !== true
            || !$this->validName($firstName)
            || !$this->validName($lastName)
            || !$this->validDisplayName($displayName)) {
            throw new RuntimeException('The JumpCloud account is missing an approved role or required profile data.');
        }

        return new RecordsPrincipal(
            $email,
            $role,
            hash('sha256', implode('|', [$this->issuer, $subject, $this->clientId, $role, $firstName, $lastName, $displayName])),
            $firstName,
            $lastName,
            'jumpcloud',
            $displayName,
        );
    }

    /** @return array<string, mixed> */
    private function verifiedIdTokenClaims(string $token, string $expectedNonce, string $accessToken): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('JumpCloud returned a malformed identity token.');
        }

        [$headerPart, $claimsPart, $signaturePart] = $parts;
        $header = $this->decodeJsonPart($headerPart);
        $claims = $this->decodeJsonPart($claimsPart);
        $signature = self::base64UrlDecode($signaturePart);
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';
        if (($header['alg'] ?? null) !== 'RS256' || $kid === '' || strlen($kid) > 200 || $signature === '') {
            throw new RuntimeException('JumpCloud returned an unsupported identity token.');
        }

        $keys = $this->httpClient->getJson($this->endpoint('/.well-known/jwks.json'));
        $matchingKey = null;
        foreach (($keys['keys'] ?? []) as $key) {
            if (is_array($key)
                && ($key['kid'] ?? null) === $kid
                && ($key['kty'] ?? null) === 'RSA'
                && (!isset($key['alg']) || $key['alg'] === 'RS256')
                && (!isset($key['use']) || $key['use'] === 'sig')) {
                $matchingKey = $key;
                break;
            }
        }
        if ($matchingKey === null) {
            throw new RuntimeException('JumpCloud signing credentials could not be verified.');
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyPem($matchingKey));
        if ($publicKey === false
            || openssl_verify($headerPart . '.' . $claimsPart, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('JumpCloud identity signature verification failed.');
        }

        $now = time();
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        $subject = $claims['sub'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        if (($claims['iss'] ?? null) !== $this->issuer
            || !in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId)
            || !is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || !is_int($expiresAt)
            || $expiresAt < $now - self::CLOCK_SKEW
            || !is_int($issuedAt)
            || $issuedAt > $now + self::CLOCK_SKEW
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::CLOCK_SKEW))
            || !is_string($claims['nonce'] ?? null)
            || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new RuntimeException('JumpCloud identity claims could not be verified.');
        }
        if (isset($claims['at_hash'])) {
            $expectedAccessTokenHash = self::base64UrlEncode(substr(hash('sha256', $accessToken, true), 0, 16));
            if (!is_string($claims['at_hash']) || !hash_equals($expectedAccessTokenHash, $claims['at_hash'])) {
                throw new RuntimeException('JumpCloud access-token binding could not be verified.');
            }
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function roleFromClaims(array $claims): ?string
    {
        $groups = $claims[$this->groupsClaim] ?? null;
        if (is_string($groups)) {
            $groups = [$groups];
        }
        if (!is_array($groups)) {
            return null;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (is_string($group) && $group !== '' && strlen($group) <= 128) {
                $normalized[] = strtolower(trim($group));
            }
        }

        foreach (['admin', 'operator', 'viewer'] as $role) {
            if (in_array(strtolower($this->roleGroups[$role]), $normalized, true)) {
                return $role;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function decodeJsonPart(string $encoded): array
    {
        $decoded = self::base64UrlDecode($encoded);
        if ($decoded === '' || strlen($decoded) > 32768) {
            throw new RuntimeException('JumpCloud returned a malformed identity token.');
        }

        try {
            $value = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('JumpCloud returned a malformed identity token.');
        }
        if (!is_array($value)) {
            throw new RuntimeException('JumpCloud returned a malformed identity token.');
        }
        return $value;
    }

    /** @param array<string, mixed> $jwk */
    private function publicKeyPem(array $jwk): string
    {
        $modulus = is_string($jwk['n'] ?? null) ? self::base64UrlDecode($jwk['n']) : '';
        $exponent = is_string($jwk['e'] ?? null) ? self::base64UrlDecode($jwk['e']) : '';
        if ($modulus === '' || $exponent === '' || strlen($modulus) > 1024 || strlen($exponent) > 8) {
            throw new RuntimeException('JumpCloud returned an invalid signing key.');
        }

        $rsaPublicKey = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));
        $rsaAlgorithm = hex2bin('300d06092a864886f70d0101010500');
        if (!is_string($rsaAlgorithm)) {
            throw new RuntimeException('JumpCloud signing credentials could not be verified.');
        }
        $subjectPublicKey = self::derSequence($rsaAlgorithm . "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $value): string
    {
        while (strlen($value) > 1 && $value[0] === "\x00") {
            $value = substr($value, 1);
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derSequence(string $value): string
    {
        return "\x30" . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->issuer, '/') . $path;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('JumpCloud login is not configured.');
        }
    }

    private function validRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false || strlen($uri) > 2048) {
            return false;
        }
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
    }

    private function validName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) === 1;
    }

    private function validDisplayName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,98}$/u", $name) === 1;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return '';
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($decoded) ? $decoded : '';
    }
}
