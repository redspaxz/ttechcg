<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class PickupsheetCountryPolicy
{
    /** @var array<string, true> */
    private array $allowedCountries = [];

    /** @param list<string> $allowedCountries */
    public function __construct(private readonly bool $enabled, array $allowedCountries = ['CM', 'NG'])
    {
        foreach ($allowedCountries as $country) {
            $country = strtoupper(trim($country));
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $this->allowedCountries[$country] = true;
            }
        }

        if ($this->allowedCountries === []) {
            $this->allowedCountries = ['CM' => true, 'NG' => true];
        }
    }

    public static function fromEnvironment(bool $production): self
    {
        $enabledValue = getenv('PICKUPSHEET_GEO_RESTRICTION_ENABLED');
        $parsedEnabled = is_string($enabledValue) && trim($enabledValue) !== ''
            ? filter_var($enabledValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
        $enabled = $production && (is_bool($parsedEnabled) ? $parsedEnabled : true);
        $countryValue = getenv('PICKUPSHEET_ALLOWED_COUNTRIES');
        $countries = is_string($countryValue) && trim($countryValue) !== ''
            ? preg_split('/\s*,\s*/', trim($countryValue))
            : ['CM', 'NG'];

        return new self($enabled, is_array($countries) ? $countries : ['CM', 'NG']);
    }

    public function allows(Request $request): bool
    {
        if (!$this->enabled || !$this->protects($request->path)) {
            return true;
        }

        $country = strtoupper($request->header('CF-IPCountry'));
        return preg_match('/^[A-Z]{2}$/', $country) === 1
            && isset($this->allowedCountries[$country]);
    }

    private function protects(string $path): bool
    {
        return $path === '/dhl/pickupsheet'
            || str_starts_with($path, '/dhl/pickupsheet/')
            || $path === '/pickupsheet'
            || str_starts_with($path, '/pickupsheet/');
    }
}
