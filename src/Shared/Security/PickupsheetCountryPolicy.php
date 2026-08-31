<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class PickupsheetCountryPolicy
{
    /**
     * UN M49 North America: Northern America, Central America, and Caribbean.
     *
     * @var array<string, true>
     */
    private const BLOCKED_COUNTRIES = [
        // Northern America
        'BM' => true, 'CA' => true, 'GL' => true, 'PM' => true, 'US' => true,
        // Central America
        'BZ' => true, 'CR' => true, 'SV' => true, 'GT' => true, 'HN' => true,
        'MX' => true, 'NI' => true, 'PA' => true,
        // Caribbean
        'AI' => true, 'AG' => true, 'AW' => true, 'BS' => true, 'BB' => true,
        'BQ' => true, 'VG' => true, 'KY' => true, 'CU' => true, 'CW' => true,
        'DM' => true, 'DO' => true, 'GD' => true, 'GP' => true, 'HT' => true,
        'JM' => true, 'MQ' => true, 'MS' => true, 'PR' => true, 'BL' => true,
        'KN' => true, 'LC' => true, 'MF' => true, 'VC' => true, 'SX' => true,
        'TT' => true, 'TC' => true, 'VI' => true,
    ];

    /** @var array<string, true> */
    private const UNRESOLVED_COUNTRY_CODES = ['XX' => true, 'T1' => true];

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
        return preg_match('/^[A-Z]{2}$/', $country) === 1
            && !isset(self::UNRESOLVED_COUNTRY_CODES[$country])
            && !isset(self::BLOCKED_COUNTRIES[$country]);
    }

    private function protects(string $path): bool
    {
        return $path === '/dhl/pickupsheet'
            || str_starts_with($path, '/dhl/pickupsheet/')
            || $path === '/pickupsheet'
            || str_starts_with($path, '/pickupsheet/');
    }
}
