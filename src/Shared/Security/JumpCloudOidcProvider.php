<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Closure;
use JsonException;
use RuntimeException;

final class JumpCloudOidcProvider implements IdentityProvider
{
    private const ALLOWED_ISSUERS = [
        'https://oauth.id.jumpcloud.com/',
        'https://oauth.id.eu.jumpcloud.com/',
        'https://oauth.id.in.jumpcloud.com/',
    ];

    private string $issuer;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    /**
     * @param array<string, mixed> $config
     * @param (Closure(string, string, array<string, string>, array<int, string>): array<string, mixed>)|null $httpClient
     */
    public function __construct(array $config, private readonly ?Closure $httpClient = null)
    {
        $this->issuer = rtrim((string) ($config['jumpcloud_oidc_issuer'] ?? ''), '/') . '/';
        $this->clientId = trim((string) ($config['jumpcloud_oidc_client_id'] ?? ''));
        $this->clientSecret = trim((string) ($config['jumpcloud_oidc_client_secret'] ?? ''));
        $this->redirectUri = trim((string) ($config['jumpcloud_oidc_redirect_uri'] ?? ''));
    }

    public function configured(): bool
    {
        return in_array($this->issuer, self::ALLOWED_ISSUERS, true)
            && (bool) preg_match('/^[A-Za-z0-9._-]{8,200}$/', $this->clientId)
            && !str_starts_with($this->clientId, 'replace-with-')
            && strlen($this->clientSecret) >= 16
            && strlen($this->clientSecret) <= 500
            && !str_starts_with($this->clientSecret, 'replace-with-')
            && $this->validRedirectUri($this->redirectUri)
            && function_exists('openssl_verify')
            && ($this->httpClient !== null || function_exists('curl_init'));
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->issuer,
            $this->clientId,
            $this->redirectUri,
            $this->clientSecret,
        ]));
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $this->assertConfigured();
        if (!$this->validOpaqueValue($state, 32, 200)
            || !$this->validOpaqueValue($nonce, 32, 200)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/', $codeChallenge)
        ) {
            throw new RuntimeException('Unable to start a secure JumpCloud login transaction.');
        }

        return $this->issuer . 'oauth2/auth?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function authenticate(string $code, string $codeVerifier, string $nonce): array
    {
        $this->assertConfigured();
        if (!$this->validOpaqueValue($code, 4, 4096)
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $codeVerifier)
            || !$this->validOpaqueValue($nonce, 32, 200)
        ) {
            throw new RuntimeException('JumpCloud returned an invalid authentication response.');
        }

        $tokens = $this->requestJson('POST', $this->issuer . 'oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
        ]);

        $accessToken = $this->requiredString($tokens, 'access_token', 4, 12000);
        $idToken = $this->requiredString($tokens, 'id_token', 20, 30000);
        $tokenType = strtolower($this->requiredString($tokens, 'token_type', 3, 20));
        if ($tokenType !== 'bearer') {
            throw new RuntimeException('JumpCloud returned an unsupported token type.');
        }

        $claims = $this->verifiedIdTokenClaims($idToken, $nonce);
        $userInfo = $this->requestJson(
            'GET',
            $this->issuer . 'userinfo',
            [],
            ['Authorization: Bearer ' . $accessToken],
        );

        $subject = $this->requiredString($claims, 'sub', 1, 300);
        if (!hash_equals($subject, $this->requiredString($userInfo, 'sub', 1, 300))) {
            throw new RuntimeException('JumpCloud returned inconsistent user identity claims.');
        }

        $identityClaims = array_merge($claims, $userInfo);
        $username = $this->firstClaim($identityClaims, ['preferred_username', 'username', 'email', 'sub']);
        $name = $this->firstClaim($identityClaims, ['name']);
        if ($name === '') {
            $name = trim($this->firstClaim($identityClaims, ['given_name']) . ' ' . $this->firstClaim($identityClaims, ['family_name']));
        }
        if ($name === '') {
            $name = $username;
        }

        if ($username === '' || $name === '') {
            throw new RuntimeException('JumpCloud did not return the required operator identity claims.');
        }

        return [
            'sub' => $subject,
            'name' => $name,
            'username' => $username,
            'email' => $this->firstClaim($identityClaims, ['email']),
            'expires_at' => (int) $claims['exp'],
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedIdTokenClaims(string $idToken, string $nonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('JumpCloud returned an invalid identity token.');
        }

        $header = $this->decodeJsonSegment($parts[0]);
        $claims = $this->decodeJsonSegment($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('JumpCloud returned an unsupported identity-token algorithm.');
        }

        $keyId = $this->requiredString($header, 'kid', 1, 300);
        $jwks = $this->requestJson('GET', $this->issuer . '.well-known/jwks.json');
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new RuntimeException('JumpCloud signing keys are unavailable.');
        }

        $matchingKey = null;
        foreach ($keys as $key) {
            if (is_array($key)
                && ($key['kid'] ?? null) === $keyId
                && ($key['kty'] ?? null) === 'RSA'
                && (!isset($key['alg']) || $key['alg'] === 'RS256')
                && (!isset($key['use']) || $key['use'] === 'sig')
            ) {
                $matchingKey = $key;
                break;
            }
        }
        if ($matchingKey === null) {
            throw new RuntimeException('The JumpCloud identity-token signing key was not found.');
        }

        $verification = openssl_verify(
            $parts[0] . '.' . $parts[1],
            $signature,
            $this->rsaPublicKeyPem($matchingKey),
            OPENSSL_ALGO_SHA256,
        );
        if ($verification !== 1) {
            throw new RuntimeException('The JumpCloud identity-token signature is invalid.');
        }

        $now = time();
        $issuer = $this->requiredString($claims, 'iss', 1, 300);
        $expiresAt = is_int($claims['exp'] ?? null) ? $claims['exp'] : 0;
        $issuedAt = is_int($claims['iat'] ?? null) ? $claims['iat'] : 0;
        $notBefore = is_int($claims['nbf'] ?? null) ? $claims['nbf'] : 0;
        $tokenNonce = $this->requiredString($claims, 'nonce', 1, 300);

        if (!hash_equals($this->issuer, $issuer)
            || $expiresAt < $now - 60
            || $issuedAt <= 0
            || $issuedAt > $now + 60
            || ($notBefore > 0 && $notBefore > $now + 60)
            || !hash_equals($nonce, $tokenNonce)
            || !$this->audienceContainsClient($claims['aud'] ?? null)
        ) {
            throw new RuntimeException('JumpCloud identity-token claims could not be validated.');
        }

        if (is_array($claims['aud'] ?? null) && count($claims['aud']) > 1) {
            $authorizedParty = $this->requiredString($claims, 'azp', 1, 300);
            if (!hash_equals($this->clientId, $authorizedParty)) {
                throw new RuntimeException('JumpCloud returned an invalid authorized party.');
            }
        }

        return $claims;
    }

    private function audienceContainsClient(mixed $audience): bool
    {
        if (is_string($audience)) {
            return hash_equals($this->clientId, $audience);
        }
        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $value) {
            if (is_string($value) && hash_equals($this->clientId, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $jwk */
    private function rsaPublicKeyPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode($this->requiredString($jwk, 'n', 20, 2000));
        $exponent = $this->base64UrlDecode($this->requiredString($jwk, 'e', 1, 20));
        $rsaKey = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) {
            throw new RuntimeException('Unable to prepare the JumpCloud signing key.');
        }
        $subjectPublicKey = $this->derSequence($algorithm . "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    /** @return array<string, mixed> */
    private function decodeJsonSegment(string $segment): array
    {
        try {
            $decoded = json_decode($this->base64UrlDecode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('JumpCloud returned an invalid identity token.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('JumpCloud returned an invalid identity token.');
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new RuntimeException('JumpCloud returned invalid encoded data.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false) {
            throw new RuntimeException('JumpCloud returned invalid encoded data.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, int $minimum, int $maximum): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || strlen($value) < $minimum || strlen($value) > $maximum) {
            throw new RuntimeException('JumpCloud returned an incomplete authentication response.');
        }

        return $value;
    }

    /** @param array<string, mixed> $claims @param array<int, string> $names */
    private function firstClaim(array $claims, array $names): string
    {
        foreach ($names as $name) {
            $value = $claims[$name] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '' && strlen($value) <= 300) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $form
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $form = [], array $headers = []): array
    {
        if ($this->httpClient !== null) {
            $result = ($this->httpClient)($method, $url, $form, $headers);
            if (!is_array($result)) {
                throw new RuntimeException('JumpCloud returned an invalid response.');
            }

            return $result;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to contact JumpCloud.');
        }

        $requestHeaders = array_merge(['Accept: application/json'], $headers);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $body === false;
        curl_close($handle);

        if ($failed || $status < 200 || $status >= 300 || !is_string($body) || strlen($body) > 1000000) {
            throw new RuntimeException('JumpCloud authentication is temporarily unavailable.');
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('JumpCloud returned an invalid response.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('JumpCloud returned an invalid response.');
        }

        return $decoded;
    }

    private function validRedirectUri(string $uri): bool
    {
        if (strlen($uri) < 12 || strlen($uri) > 1000 || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true));
    }

    private function validOpaqueValue(string $value, int $minimum, int $maximum): bool
    {
        return strlen($value) >= $minimum
            && strlen($value) <= $maximum
            && (bool) preg_match('/^[A-Za-z0-9._~-]+$/', $value);
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) {
            throw new RuntimeException('JumpCloud OIDC is not configured.');
        }
    }
}
