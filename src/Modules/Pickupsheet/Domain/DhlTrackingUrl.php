<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

final class DhlTrackingUrl
{
    private const BASE_URL = 'https://www.dhl.com/cm-en/home/tracking.html';

    public static function forAwb(string $awbNumber): string
    {
        return self::BASE_URL
            . '?tracking-id=' . rawurlencode(trim($awbNumber))
            . '&submit=1&inputsource=marketingstage';
    }
}
