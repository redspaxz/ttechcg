<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;
use App\Shared\Http\Response;

final class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; img-src 'self' data: https://www.google-analytics.com https://*.google-analytics.com; object-src 'none'; script-src 'self' https://www.googletagmanager.com; connect-src 'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com; style-src 'self'";

    public function __construct(private readonly bool $production)
    {
    }

    public function apply(Response $response, Request $request): Response
    {
        $headers = [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'X-Request-ID' => $request->requestId(),
        ];
        if ($this->production) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        if ($this->protectsSensitiveWorkspace($request->path)) {
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $response->withHeaders($headers);
    }

    private function protectsSensitiveWorkspace(string $path): bool
    {
        return $path === '/dhl/pickupsheet'
            || str_starts_with($path, '/dhl/pickupsheet/')
            || $path === '/pickupsheet'
            || str_starts_with($path, '/pickupsheet/');
    }
}
