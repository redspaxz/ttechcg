<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

final class CustomerProfile
{
    public const POINTS_PER_KILOGRAM = 10;

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
        public readonly int $rewardAdjustmentPoints = 0,
        public readonly int $rewardEarnedAdjustmentPoints = 0,
        public readonly int $cargoWeightRewardPoints = 0,
    ) {
    }

    public function followUpDue(?string $today = null): bool
    {
        return $this->nextFollowUpOn !== null
            && $this->nextFollowUpOn <= ($today ?? gmdate('Y-m-d'))
            && $this->status !== 'inactive';
    }

    public function shipmentRewardPoints(): int
    {
        return $this->cargoRewardPoints();
    }

    public function cargoRewardPoints(): int
    {
        return max(0, $this->cargoWeightRewardPoints);
    }

    public function rewardBalance(): int
    {
        return max(0, $this->cargoRewardPoints() + $this->rewardAdjustmentPoints);
    }

    public function lifetimeEarnedPoints(): int
    {
        return $this->cargoRewardPoints() + max(0, $this->rewardEarnedAdjustmentPoints);
    }

    public function loyaltyTier(): string
    {
        return match (true) {
            $this->lifetimeEarnedPoints() >= 500 => 'Platinum',
            $this->lifetimeEarnedPoints() >= 250 => 'Gold',
            $this->lifetimeEarnedPoints() >= 100 => 'Silver',
            default => 'Bronze',
        };
    }
}
