<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function validate(string $token): bool
    {
        return isset($_SESSION[self::SESSION_KEY])
            && $token !== ''
            && hash_equals((string) $_SESSION[self::SESSION_KEY], $token);
    }
}

