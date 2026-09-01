<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

interface PickupSheetRepository
{
    public function create(PickupSheet $pickupSheet): PickupSheet;

    public function update(PickupSheet $pickupSheet, string $actorId): PickupSheet;

    public function markPaid(string $referenceNumber, string $actorId): PickupSheet;

    public function delete(string $referenceNumber, string $actorId): void;

    /** @return list<PickupSheet> */
    public function recent(int $limit, int $offset = 0, string $search = ''): array;

    public function count(string $search = ''): int;

    /** @return array{sheetCount: int, shipmentCount: int, totalCashXaf: int, latestCreatedAt: ?string} */
    public function summary(): array;

    /** @return list<array{date: string, sheetCount: int, shipmentCount: int, totalCashXaf: int}> */
    public function activityByDay(int $days): array;

    /** @return list<array{destination: string, shipmentCount: int, totalCashXaf: int}> */
    public function topDestinations(int $limit): array;

    /** @return list<array{sender: string, shipmentCount: int}> */
    public function topSenders(int $months, int $limit): array;

    /** @return list<string> */
    public function consignorSuggestions(string $query, int $limit): array;

    public function findByReference(string $referenceNumber): ?PickupSheet;
}
