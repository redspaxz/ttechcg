<?php

declare(strict_types=1);

namespace App\Shared\Security;

use RuntimeException;

final class PasswordHasher
{
    private const ARGON2_MEMORY_KIB = 19456;
    private const ARGON2_TIME_COST = 2;
    private const ARGON2_THREADS = 1;
    private const BCRYPT_COST = 12;

    public static function hash(string $password): string
    {
        if (!defined('PASSWORD_ARGON2ID') && strlen($password) > 72) {
            throw new RuntimeException('This server cannot safely hash passwords longer than 72 characters.');
        }
        $hash = password_hash($password, self::algorithm(), self::options());
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to secure the account password.');
        }
        return $hash;
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    public static function maximumLength(): int
    {
        return defined('PASSWORD_ARGON2ID') ? 128 : 72;
    }

    private static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private static function options(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? [
                'memory_cost' => self::ARGON2_MEMORY_KIB,
                'time_cost' => self::ARGON2_TIME_COST,
                'threads' => self::ARGON2_THREADS,
            ]
            : ['cost' => self::BCRYPT_COST];
    }
}
