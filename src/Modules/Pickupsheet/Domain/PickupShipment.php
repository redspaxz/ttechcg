<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

final class PickupShipment
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $consignor,
        public readonly string $awbNumber,
        public readonly string $destination,
        public readonly int $amountXaf,
        public readonly int $pieces,
        public readonly string $weightKg,
        public readonly string $collectionTime,
        public readonly string $checkedBy,
    ) {
    }
}
