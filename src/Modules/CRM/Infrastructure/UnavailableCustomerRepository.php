<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use RuntimeException;

final class UnavailableCustomerRepository implements CustomerRepository
{
    public function synchronizeFromShipments(): void
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function paginated(string $search, string $status, int $limit, int $offset): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function summary(): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function recentShipments(string $customerKey, int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function rewardAdjustments(string $customerKey, int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
    ): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }
}
