<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

use DateTimeImmutable;

/**
 * DHL recycles AWB numbers after a fixed period (about three months), so an AWB only identifies a
 * shipment within that window around its collection date.
 */
final class AwbReusePolicy
{
    public const DEFAULT_DAYS = 90;
    private static int $days = self::DEFAULT_DAYS;

    public static function configure(int $days): void
    {
        self::$days = max(1, min($days, 3650));
    }

    public static function days(): int
    {
        return self::$days;
    }

    /**
     * Collection dates whose AWBs could still belong to the same shipment as one collected on $collectionDate.
     *
     * @return array{0: string, 1: string} inclusive from and to dates (Y-m-d)
     */
    public static function window(string $collectionDate): array
    {
        $date = self::date($collectionDate) ?? new DateTimeImmutable('today');
        $span = self::$days - 1;
        return [
            $date->modify('-' . $span . ' days')->format('Y-m-d'),
            $date->modify('+' . $span . ' days')->format('Y-m-d'),
        ];
    }

    /** False once the AWB may have been reissued, so its DHL tracking page could show another shipment. */
    public static function trackingIsLive(string $collectionDate, ?string $today = null): bool
    {
        $collected = self::date($collectionDate);
        $current = self::date($today ?? gmdate('Y-m-d')) ?? new DateTimeImmutable('today');
        if ($collected === null) {
            return true;
        }
        return $collected->modify('+' . self::$days . ' days') > $current;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10));
        return $date === false ? null : $date;
    }
}
