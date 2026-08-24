<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

interface PickupSheetRepository
{
    public function create(PickupSheet $pickupSheet): PickupSheet;

    /** @return list<PickupSheet> */
    public function recent(int $limit): array;

    public function findByReference(string $referenceNumber): ?PickupSheet;
}
