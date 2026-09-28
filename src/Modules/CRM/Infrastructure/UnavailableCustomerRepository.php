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

    public function paginated(array $filters, int $limit, int $offset): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function summary(): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function topByRewardPoints(int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function suggestions(string $query, int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function duplicateReviewNames(int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function findByName(string $name): ?array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function recentShipments(string $customerKey, int $limit, int $offset = 0): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function shipmentCount(string $customerKey): int
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function save(CustomerProfile $customer, string $actorId, ?string $expectedUpdatedAt = null): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function delete(string $customerKey, string $actorId): void
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function activities(string $customerKey, int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function addActivity(
        string $customerKey,
        string $type,
        string $occurredOn,
        string $summary,
        ?string $nextFollowUpOn,
        string $actorId,
        string $actorName,
    ): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function recentMerges(int $limit): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function undoMerge(int $mergeId, string $actorId): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function dismissMerge(int $mergeId, string $actorId): void
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function rewardHistory(string $customerKey, int $limit, int $offset = 0): array
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function rewardHistoryCount(string $customerKey): int
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function activityCount(string $customerKey): int
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
        string $actorName = '',
    ): CustomerProfile
    {
        throw new RuntimeException('Customer storage is unavailable.');
    }
}
