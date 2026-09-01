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

    public function update(PickupSheet $pickupSheet, string $actorId): PickupSheet
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function markPaid(string $referenceNumber, string $actorId): PickupSheet
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function delete(string $referenceNumber, string $actorId): void
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function recent(int $limit, int $offset = 0, string $search = ''): array
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }

    public function count(string $search = ''): int
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }

    public function summary(): array
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function activityByDay(int $days): array
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function topDestinations(int $limit): array
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function topSenders(int $months, int $limit): array
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function consignorSuggestions(string $query, int $limit): array
    {
        throw new RuntimeException('Pickup-sheet storage is unavailable.');
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        throw new RuntimeException('Persistent pickup-sheet storage is unavailable.');
    }
}
