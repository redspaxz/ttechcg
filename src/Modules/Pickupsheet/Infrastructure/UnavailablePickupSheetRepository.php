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

    public function recent(int $limit, int $offset = 0): array
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }

    public function count(): int
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }
}
