<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class UnsafeRequestPolicy
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private readonly ?string $applicationOrigin;

    public function __construct(string $applicationUrl)
    {
        $this->applicationOrigin = $this->origin($applicationUrl);
    }

    /** Returns a stable audit reason when the request should be rejected. */
    public function denialReason(Request $request): ?string
    {
        if (in_array(strtoupper($request->method), self::SAFE_METHODS, true)) {
            return null;
        }

        $fetchSite = strtolower($request->header('Sec-Fetch-Site'));
        if (in_array($fetchSite, ['cross-site', 'same-site', 'none'], true)) {
            return 'fetch_metadata';
        }

        $source = $request->header('Origin');
        if ($source === '') {
            $source = $request->header('Referer');
        }
        if ($source === '') {
            // Older/privacy-focused clients may omit both headers. Route-level
            // synchronizer tokens remain mandatory for every state change.
            return null;
        }

        $sourceOrigin = $this->origin($source);
        if ($sourceOrigin === null || $this->applicationOrigin === null
            || !hash_equals($this->applicationOrigin, $sourceOrigin)) {
            return 'origin_mismatch';
        }

        return null;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if (!in_array($scheme, ['http', 'https'], true)
            || preg_match('/^[a-z0-9.-]{1,253}$/', $host) !== 1) {
            return null;
        }
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        return $scheme . '://' . $host . ($port !== null && !$defaultPort ? ':' . $port : '');
    }
}
