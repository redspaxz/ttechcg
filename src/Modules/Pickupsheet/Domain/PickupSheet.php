<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

final class PickupSheet
{
    /** @param list<PickupShipment> $shipments */
    public function __construct(
        public readonly ?int $id,
        public readonly string $referenceNumber,
        public readonly string $agentName,
        public readonly string $collectionDate,
        public readonly array $shipments,
        public readonly int $totalCashReceivedXaf,
        public readonly string $privacyConsentAt,
        public readonly string $privacyNoticeVersion,
        public readonly string $createdAt,
        public readonly string $status = 'open',
        public readonly ?string $paidAt = null,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function shipmentCount(): int
    {
        return count($this->shipments);
    }
}
