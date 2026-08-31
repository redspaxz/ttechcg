<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class PickupsheetCountryPolicy
{
    /** @var array<string, true> */
    private const ALLOWED_COUNTRIES = ['CM' => true, 'NG' => true];

    public function __construct(private readonly bool $enabled)
    {
    }

    public static function fromEnvironment(bool $production): self
    {
        $enabledValue = getenv('PICKUPSHEET_GEO_RESTRICTION_ENABLED');
        $parsedEnabled = is_string($enabledValue) && trim($enabledValue) !== ''
            ? filter_var($enabledValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
        $enabled = $production && (is_bool($parsedEnabled) ? $parsedEnabled : true);

        return new self($enabled);
    }

    public function allows(Request $request): bool
    {
        if (!$this->enabled || !$this->protects($request->path)) {
            return true;
        }

        $country = strtoupper($request->trustedCloudflareHeader('CF-IPCountry'));
        return isset(self::ALLOWED_COUNTRIES[$country]);
    }

    private function protects(string $path): bool
    {
        return $path === '/dhl/pickupsheet'
            || str_starts_with($path, '/dhl/pickupsheet/')
            || $path === '/pickupsheet'
            || str_starts_with($path, '/pickupsheet/');
    }
}
