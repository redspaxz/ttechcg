<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class RecordsSession
{
    private const SESSION_KEY = '_pickupsheet_identity';
    private const ABSOLUTE_LIFETIME = 28800;
    private const IDLE_LIFETIME = 3600;

    public function login(RecordsPrincipal $principal): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $now = time();
        $_SESSION[self::SESSION_KEY] = [
            'username' => $principal->username,
            'version' => $principal->authenticationVersion,
            'role' => $principal->role,
            'first_name' => $principal->firstName,
            'last_name' => $principal->lastName,
            'display_name' => $principal->displayName,
            'identity_provider' => $principal->identityProvider,
            'issued_at' => $now,
            'last_seen_at' => $now,
        ];
    }

    public function principal(RecordsAccess $access): ?RecordsPrincipal
    {
        $identity = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($identity)) {
            return null;
        }

        $username = is_string($identity['username'] ?? null) ? $identity['username'] : '';
        $version = is_string($identity['version'] ?? null) ? $identity['version'] : '';
        $identityProvider = is_string($identity['identity_provider'] ?? null) ? $identity['identity_provider'] : 'local';
        $issuedAt = is_int($identity['issued_at'] ?? null) ? $identity['issued_at'] : 0;
        $lastSeenAt = is_int($identity['last_seen_at'] ?? null) ? $identity['last_seen_at'] : 0;
        $now = time();

        if ($username === ''
            || $version === ''
            || $issuedAt < $now - self::ABSOLUTE_LIFETIME
            || $lastSeenAt < $now - self::IDLE_LIFETIME) {
            $this->forget();
            return null;
        }

        if (in_array($identityProvider, ['jumpcloud', 'cloudflare_access'], true)) {
            $role = is_string($identity['role'] ?? null) ? $identity['role'] : '';
            $firstName = is_string($identity['first_name'] ?? null) ? $identity['first_name'] : '';
            $lastName = is_string($identity['last_name'] ?? null) ? $identity['last_name'] : '';
            $displayName = is_string($identity['display_name'] ?? null) ? $identity['display_name'] : '';
            if (!RecordsPrincipal::isValidRole($role)
                || filter_var($username, FILTER_VALIDATE_EMAIL) === false
                || strlen($username) > 100
                || preg_match('/^[a-f0-9]{64}$/', $version) !== 1
                || !$this->validName($firstName)
                || !$this->validName($lastName)
                || !$this->validDisplayName($displayName)) {
                $this->forget();
                return null;
            }

            $_SESSION[self::SESSION_KEY]['last_seen_at'] = $now;
            return new RecordsPrincipal($username, $role, $version, $firstName, $lastName, $identityProvider, $displayName);
        }
        if ($identityProvider !== 'local') {
            $this->forget();
            return null;
        }

        $principal = $access->resolvePrincipal($username);
        if ($principal === null || !hash_equals($version, $principal->authenticationVersion)) {
            $this->forget();
            return null;
        }

        $_SESSION[self::SESSION_KEY]['last_seen_at'] = $now;
        return $principal;
    }

    public function logout(): void
    {
        $this->forget();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function forget(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    private function validName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) === 1;
    }

    private function validDisplayName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,98}$/u", $name) === 1;
    }
}
