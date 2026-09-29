<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Modules\Pickupsheet\Domain\AwbReusePolicy;
use App\Modules\Pickupsheet\Domain\DhlTrackingUrl;

/** Renders an AWB as a DHL tracking link while it is live, and as plain text once DHL may have reissued it. */
final class AwbLink
{
    public static function html(string $awbNumber, string $collectionDate): string
    {
        $awb = htmlspecialchars($awbNumber, ENT_QUOTES, 'UTF-8');
        if (AwbReusePolicy::trackingIsLive($collectionDate)) {
            $url = htmlspecialchars(DhlTrackingUrl::forAwb($awbNumber), ENT_QUOTES, 'UTF-8');
            return '<a class="pickup-awb-link" href="' . $url . '" target="_blank" rel="noopener noreferrer" aria-label="Track AWB ' . $awb . ' with DHL">' . $awb . '</a>';
        }
        $title = htmlspecialchars(sprintf(
            'DHL reissues AWB numbers after %d days, so tracking for this number may now show a different shipment.',
            AwbReusePolicy::days(),
        ), ENT_QUOTES, 'UTF-8');
        return '<span class="pickup-awb-expired" title="' . $title . '">' . $awb . ' <small>tracking expired</small></span>';
    }
}
