<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Security\PickupsheetCountryPolicy;
use App\Shared\Security\SecurityLogger;
use App\Shared\Security\UnsafeRequestPolicy;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly ?PickupsheetCountryPolicy $countryPolicy = null,
        private readonly ?SecurityLogger $securityLogger = null,
        private readonly ?UnsafeRequestPolicy $unsafeRequestPolicy = null,
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
                return Response::html(
                    'Cross-site state-changing requests are not allowed.',
                    403,
                    [
                        'Cache-Control' => 'private, no-store, max-age=0',
                        'Vary' => 'Sec-Fetch-Site, Origin',
                        'X-Robots-Tag' => 'noindex, nofollow',
                    ],
                );
            }
            if ($this->countryPolicy?->allows($request) === false) {
                $country = strtoupper($request->header('CF-IPCountry'));
                $this->securityLogger?->event('pickupsheet.country_access', $request, 'denied', [
                    'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'missing_or_invalid',
                ]);
                return Response::html(
                    '<h1>Access unavailable</h1><p>Pickupsheet is not available from this location.</p>',
                    403,
                    [
                        'Cache-Control' => 'private, no-store, max-age=0',
                        'Vary' => 'CF-IPCountry',
                        'X-Robots-Tag' => 'noindex, nofollow',
                    ],
                );
            }
            return $this->router->dispatch($request);
        } catch (Throwable $exception) {
            error_log($exception->__toString());
            return Response::html('<h1>Something went wrong</h1><p>Please try again shortly.</p>', 500);
        }
    }
}

