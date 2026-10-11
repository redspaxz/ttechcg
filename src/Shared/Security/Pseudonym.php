<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Pseudonymous identifiers for actors and clients in stored records and security events.
 *
 * With SECURITY_PSEUDONYM_KEY set, identifiers are HMAC-SHA256 values that cannot be
 * reversed by hashing a list of usernames or every IPv4 address. Without it they fall
 * back to plain SHA-256 so that records written before the key was introduced still
 * resolve. bin/rekey-pseudonyms.php converts stored actor identifiers to the keyed form.
 */
final class Pseudonym
{
    public const MINIMUM_KEY_BYTES = 32;
    private static string $key = '';

    public static function configure(string $key): void
    {
        $key = trim($key);
        self::$key = strlen($key) >= self::MINIMUM_KEY_BYTES ? $key : '';
    }

    public static function isKeyed(): bool
    {
        return self::$key !== '';
    }

    /** 24 hex characters identifying a username in `*_by`, `actor_id`, and `target_id` columns. */
    public static function actorId(string $username): string
    {
        return substr(self::digest('actor', $username), 0, 24);
    }

    /** 64 hex characters identifying a client address in rate limits and security events. */
    public static function clientId(string $address): string
    {
        return self::digest('client', $address);
    }

    /** The unkeyed actor identifier written before SECURITY_PSEUDONYM_KEY existed. */
    public static function legacyActorId(string $username): string
    {
        return substr(hash('sha256', $username), 0, 24);
    }

    private static function digest(string $purpose, string $value): string
    {
        if (self::$key === '') {
            return hash('sha256', $value);
        }
        return hash_hmac('sha256', $purpose . '|' . $value, self::$key);
    }
}
