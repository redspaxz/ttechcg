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

    public function recent(int $limit, int $offset = 0): array
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        return array_slice(array_reverse(is_array($sheets) ? $sheets : []), $offset, $limit);
    }

    public function count(): int
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($sheets) ? count($sheets) : 0;
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        foreach ($this->recent(PHP_INT_MAX) as $pickupSheet) {
            if ($pickupSheet instanceof PickupSheet && $pickupSheet->referenceNumber === $referenceNumber) {
                return $pickupSheet;
            }
        }

        return null;
    }
}
