<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use RuntimeException;

final class UnavailablePickupSheetRepository implements PickupSheetRepository
{
    public function create(PickupSheet $pickupSheet): PickupSheet
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }
}
