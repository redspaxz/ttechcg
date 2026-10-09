<?php

declare(strict_types=1);

namespace App\Shared\Text;

/**
 * Name fields (consignors, customers, contacts, agents, checkers) are stored in uppercase and may
 * contain only letters, digits, spaces, and hyphens.
 */
final class NameText
{
    public const RULE = 'may contain only letters, numbers, spaces, and hyphens';

    /** Collapses whitespace and converts to uppercase without removing any character. */
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    }

    public static function isAllowed(string $name): bool
    {
        return preg_match('/^[\p{L}\p{M}\p{N} -]*$/u', $name) === 1;
    }

    /** Removes disallowed symbols, for names taken from elsewhere (such as account names) rather than typed. */
    public static function sanitize(string $name): string
    {
        return self::normalize((string) preg_replace('/[^\p{L}\p{M}\p{N}\s-]+/u', '', $name));
    }
}
