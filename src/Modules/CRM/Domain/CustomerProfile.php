<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

final class CustomerProfile
{
    public const POINTS_PER_KILOGRAM = 10;
    public const NEW_CUSTOMER_DAYS = 15;

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
        public readonly ?string $assignedActorId = null,
        public readonly string $assignedName = '',
    ) {
    }

    /** Customer ID shown to staff, e.g. CUS-000042. Assigned once from the database row and never reused. */
    public function reference(): string
    {
        return self::referenceFor($this->id);
    }

    public static function referenceFor(?int $id): string
    {
        return $id === null || $id < 1 ? '' : str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accepts "000042" or "42", and the older "CUS-000042", "cus-42", or "CUS42" still found in exports,
     * and returns 42; anything else returns null.
     */
    public static function idFromReference(string $reference): ?int
    {
        return preg_match('/^\s*(?:cus-?)?0*([1-9][0-9]{0,17})\s*$/i', $reference, $match) === 1 ? (int) $match[1] : null;
    }

    public function followUpDue(?string $today = null): bool
    {
        return $this->nextFollowUpOn !== null
            && $this->nextFollowUpOn <= ($today ?? gmdate('Y-m-d'))
            && $this->status !== 'inactive';
    }

    /** Earliest of the profile's creation date and its first shipment, as Y-m-d. */
    public function customerSince(): ?string
    {
        $dates = array_filter([substr((string) $this->createdAt, 0, 10), (string) $this->firstShipmentOn]);

        return $dates === [] ? null : min($dates);
    }

    public function ageInDays(?string $today = null): ?int
    {
        $since = $this->customerSince();
        $sinceDate = $since === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $since, new \DateTimeZone('UTC'));
        $todayDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $today ?? gmdate('Y-m-d'), new \DateTimeZone('UTC'));

        return $sinceDate === false || $todayDate === false ? null : max(0, (int) $sinceDate->diff($todayDate)->format('%r%a'));
    }

    /** A customer is new for their first NEW_CUSTOMER_DAYS days (0 through 14). */
    public function isNew(?string $today = null): bool
    {
        $age = $this->ageInDays($today);

        return $age !== null && $age < self::NEW_CUSTOMER_DAYS;
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

    /**
     * Points redeemed beyond what the customer now holds, e.g. after a sheet that earned them was
     * deleted. Bonuses first cover this shortfall before the visible balance rises again.
     */
    public function rewardShortfall(): int
    {
        return max(0, -($this->cargoRewardPoints() + $this->rewardAdjustmentPoints));
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
