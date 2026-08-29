<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

final class CustomerProfile
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $customerKey,
        public readonly string $displayName,
        public readonly string $contactName = '',
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $address = '',
        public readonly string $city = '',
        public readonly string $countryCode = '',
        public readonly string $status = 'active',
        public readonly string $notes = '',
        public readonly ?string $nextFollowUpOn = null,
        public readonly string $source = 'manual',
        public readonly int $shipmentCount = 0,
        public readonly int $totalCashXaf = 0,
        public readonly ?string $firstShipmentOn = null,
        public readonly ?string $lastShipmentOn = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    public function followUpDue(?string $today = null): bool
    {
        return $this->nextFollowUpOn !== null
            && $this->nextFollowUpOn <= ($today ?? gmdate('Y-m-d'))
            && $this->status !== 'inactive';
    }
}
