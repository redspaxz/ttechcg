<?php

declare(strict_types=1);

namespace App\Shared\Security;

use JsonException;
use RuntimeException;

final class CloudflareAccessProvider
{
    private const CLOCK_SKEW = 60;

    private readonly bool $enabled;
    private readonly string $teamDomain;
    private readonly string $audience;
    private readonly string $groupsClaim;
    /** @var array{admin: string, operator: string, viewer: string} */
    private readonly array $roleGroups;
    private readonly OidcHttpClient $httpClient;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings, ?OidcHttpClient $httpClient = null)
    {
        $this->enabled = (bool) ($settings['enabled'] ?? false);
        $this->teamDomain = rtrim(trim((string) ($settings['team_domain'] ?? '')), '/');
        $this->audience = trim((string) ($settings['audience'] ?? ''));
        $this->groupsClaim = trim((string) ($settings['groups_claim'] ?? 'groups'));
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
            'enabled' => filter_var(getenv('CLOUDFLARE_ACCESS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
            'team_domain' => getenv('CLOUDFLARE_ACCESS_TEAM_DOMAIN') ?: '',
            'audience' => getenv('CLOUDFLARE_ACCESS_AUDIENCE') ?: '',
            'groups_claim' => getenv('CLOUDFLARE_ACCESS_GROUPS_CLAIM') ?: 'groups',
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
            || !$this->validTeamDomain($this->teamDomain)
            || preg_match('/^[A-Za-z0-9._:-]{8,512}$/', $this->audience) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/', $this->groupsClaim) !== 1) {
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

    public function authenticate(string $token): RecordsPrincipal
    {
        $this->assertConfigured();
        if ($token === '' || strlen($token) > 65536 || preg_match('/\s/', $token) === 1) {
            throw new RuntimeException('Cloudflare Access did not provide a valid application token.');
        }

        $claims = $this->verifiedClaims($token);
        $identity = $this->httpClient->getJson($this->teamDomain . '/cdn-cgi/access/get-identity', [
            'Cookie' => 'CF_Authorization=' . $token,
        ]);

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $identityEmail = strtolower(trim((string) ($identity['email'] ?? '')));
        if (($identity['service_token_status'] ?? false) === true
            || $email === ''
            || $identityEmail === ''
            || !hash_equals($email, $identityEmail)) {
            throw new RuntimeException('Cloudflare Access returned an inconsistent user identity.');
        }

        $role = $this->roleFromIdentity($identity, $claims);
        $displayName = $this->identityString($identity, 'name');
        $firstName = $this->identityString($identity, 'given_name');
        $lastName = $this->identityString($identity, 'family_name');
        if ($displayName === '') {
            $displayName = trim($firstName . ' ' . $lastName);
        }
        if (($firstName === '' || $lastName === '') && $displayName !== '') {
            $parts = preg_split('/\s+/u', $displayName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $firstName = $firstName !== '' ? $firstName : (string) array_shift($parts);
            $lastName = $lastName !== '' ? $lastName : implode(' ', $parts);
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($role === null
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 100
            || !$this->validName($firstName)
            || !$this->validName($lastName)
            || !$this->validDisplayName($displayName)) {
            throw new RuntimeException('The Cloudflare Access account is missing an approved role or required profile data.');
        }

        return new RecordsPrincipal(
            $email,
            $role,
            hash('sha256', implode('|', [$this->teamDomain, $subject, $this->audience, $role, $firstName, $lastName, $displayName])),
            $firstName,
            $lastName,
            'cloudflare_access',
            $displayName,
        );
    }

    /** @return array<string, mixed> */
    private function verifiedClaims(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Cloudflare Access returned a malformed application token.');
        }

        [$headerPart, $claimsPart, $signaturePart] = $parts;
        $header = $this->decodeJsonPart($headerPart);
        $claims = $this->decodeJsonPart($claimsPart);
        $signature = self::base64UrlDecode($signaturePart);
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';
        if (($header['alg'] ?? null) !== 'RS256'
            || $kid === ''
            || strlen($kid) > 200
            || preg_match('/^[A-Za-z0-9._:-]+$/', $kid) !== 1
            || $signature === '') {
            throw new RuntimeException('Cloudflare Access returned an unsupported application token.');
        }

        $certificates = $this->httpClient->getJson($this->teamDomain . '/cdn-cgi/access/certs');
        $certificate = '';
        foreach (($certificates['public_certs'] ?? []) as $candidate) {
            if (is_array($candidate)
                && ($candidate['kid'] ?? null) === $kid
                && is_string($candidate['cert'] ?? null)) {
                $certificate = trim($candidate['cert']);
                break;
            }
        }
        if ($certificate === ''
            || strlen($certificate) > 32768
            || preg_match('/^-----BEGIN (?:CERTIFICATE|PUBLIC KEY)-----[\s\S]+-----END (?:CERTIFICATE|PUBLIC KEY)-----$/', $certificate) !== 1) {
            throw new RuntimeException('Cloudflare Access signing credentials could not be verified.');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false
            || openssl_verify($headerPart . '.' . $claimsPart, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Cloudflare Access token signature verification failed.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        $now = time();
        if (($claims['iss'] ?? null) !== $this->teamDomain
            || !in_array($this->audience, $audiences, true)
            || ($claims['type'] ?? null) !== 'app'
            || !is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 100
            || !is_int($expiresAt)
            || $expiresAt < $now - self::CLOCK_SKEW
            || !is_int($issuedAt)
            || $issuedAt > $now + self::CLOCK_SKEW
            || $expiresAt <= $issuedAt
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::CLOCK_SKEW))) {
            throw new RuntimeException('Cloudflare Access token claims could not be verified.');
        }

        return $claims;
    }

    /** @param array<string, mixed> $identity @param array<string, mixed> $claims */
    private function roleFromIdentity(array $identity, array $claims): ?string
    {
        $groups = [];
        $this->collectGroupValues($identity['groups'] ?? null, $groups);
        $oidcFields = is_array($identity['oidc_fields'] ?? null) ? $identity['oidc_fields'] : [];
        $this->collectGroupValues($oidcFields[$this->groupsClaim] ?? null, $groups);
        $custom = is_array($claims['custom'] ?? null) ? $claims['custom'] : [];
        $this->collectGroupValues($custom[$this->groupsClaim] ?? null, $groups);

        $groups = array_unique(array_map(static fn (string $group): string => strtolower(trim($group)), $groups));
        foreach (['admin', 'operator', 'viewer'] as $role) {
            if (in_array(strtolower($this->roleGroups[$role]), $groups, true)) {
                return $role;
            }
        }
        return null;
    }

    /** @param list<string> $groups */
    private function collectGroupValues(mixed $value, array &$groups): void
    {
        if (is_string($value) && $value !== '' && strlen($value) <= 128) {
            $groups[] = $value;
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $entry) {
            if (is_int($key)) {
                $this->collectGroupValues($entry, $groups);
                continue;
            }
            if (in_array($key, ['name', 'id', 'email'], true)) {
                $this->collectGroupValues($entry, $groups);
            }
        }
    }

    /** @param array<string, mixed> $identity */
    private function identityString(array $identity, string $key): string
    {
        $value = $identity[$key] ?? null;
        if (!is_string($value)) {
            $oidcFields = is_array($identity['oidc_fields'] ?? null) ? $identity['oidc_fields'] : [];
            $value = $oidcFields[$key] ?? null;
        }
        return is_string($value) ? trim($value) : '';
    }

    /** @return array<string, mixed> */
    private function decodeJsonPart(string $encoded): array
    {
        $decoded = self::base64UrlDecode($encoded);
        if ($decoded === '' || strlen($decoded) > 32768) {
            throw new RuntimeException('Cloudflare Access returned a malformed application token.');
        }
        try {
            $value = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Cloudflare Access returned a malformed application token.');
        }
        if (!is_array($value)) {
            throw new RuntimeException('Cloudflare Access returned a malformed application token.');
        }
        return $value;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Cloudflare Access login is not configured.');
        }
    }

    private function validTeamDomain(string $domain): bool
    {
        if (filter_var($domain, FILTER_VALIDATE_URL) === false || strlen($domain) > 253) {
            return false;
        }
        $host = strtolower((string) parse_url($domain, PHP_URL_HOST));
        $path = (string) parse_url($domain, PHP_URL_PATH);
        return parse_url($domain, PHP_URL_SCHEME) === 'https'
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cloudflareaccess\.com$/', $host) === 1
            && ($path === '' || $path === '/')
            && parse_url($domain, PHP_URL_PORT) === null
            && parse_url($domain, PHP_URL_USER) === null
            && parse_url($domain, PHP_URL_QUERY) === null
            && parse_url($domain, PHP_URL_FRAGMENT) === null;
    }

    private function validName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) === 1;
    }

    private function validDisplayName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,98}$/u", $name) === 1;
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
