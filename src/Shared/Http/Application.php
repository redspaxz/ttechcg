<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Security\SecurityHeaders;
use App\Shared\Security\SecurityLogger;
use App\Shared\Security\UnsafeRequestPolicy;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly ?SecurityLogger $securityLogger = null,
        private readonly ?UnsafeRequestPolicy $unsafeRequestPolicy = null,
        private readonly ?SecurityHeaders $securityHeaders = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $unsafeRequestDenial = $this->unsafeRequestPolicy?->denialReason($request);
            if ($unsafeRequestDenial !== null) {
                $this->securityLogger?->event('request.cross_site', $request, 'denied', [
                    'reason' => $unsafeRequestDenial,
                ]);
                return $this->respond(Response::html(
                    'Cross-site state-changing requests are not allowed.',
                    403,
                    [
                        'Cache-Control' => 'private, no-store, max-age=0',
                        'Vary' => 'Sec-Fetch-Site, Origin',
                        'X-Robots-Tag' => 'noindex, nofollow',
                    ],
                ), $request);
            }
            return $this->respond($this->router->dispatch($request), $request);
        } catch (Throwable $exception) {
            error_log($exception->__toString());
            return $this->respond(Response::html('<h1>Something went wrong</h1><p>Please try again shortly.</p>', 500), $request);
        }
    }

    private function respond(Response $response, Request $request): Response
    {
        return $this->securityHeaders?->apply($response, $request) ?? $response;
    }
}

