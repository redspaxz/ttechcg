<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;

final class DemoPickupSheetRepository implements PickupSheetRepository
{
    private const SESSION_KEY = '_demo_pickup_sheets';

    public function create(PickupSheet $pickupSheet): PickupSheet
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        $created = new PickupSheet(
            count($sheets) + 1,
            $pickupSheet->referenceNumber,
            $pickupSheet->agentName,
            $pickupSheet->collectionDate,
            $pickupSheet->shipments,
            $pickupSheet->totalCashReceivedXaf,
            $pickupSheet->privacyConsentAt,
            $pickupSheet->privacyNoticeVersion,
            $pickupSheet->createdAt,
        );
        $sheets[] = $created;
        $_SESSION[self::SESSION_KEY] = $sheets;

        return $created;
    }
}
