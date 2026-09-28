<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

interface CustomerRepository
{
    public function synchronizeFromShipments(): void;

    /** @return array{items: list<CustomerProfile>, totalRecords: int} */
    public function paginated(string $search, string $status, int $limit, int $offset): array;

    /** @return array{customerCount: int, activeCount: int, attentionCount: int, followUpsDue: int} */
    public function summary(): array;

    /** @return list<CustomerProfile> */
    public function topByRewardPoints(int $limit): array;

    /** @return list<string> */
    public function suggestions(string $query, int $limit): array;

    /**
     * Lightweight name index for duplicate detection across every profile.
     *
     * @return list<array{customerKey: string, displayName: string}>
     */
    public function duplicateReviewNames(int $limit): array;

    public function find(string $customerKey): ?CustomerProfile;

    /**
     * Finds the profile that owns a name, either as its display name or as a merged-away alias.
     *
     * @return array{customer: CustomerProfile, alias: ?string}|null
     */
    public function findByName(string $name): ?array;

    /**
     * @return list<array{
     *     referenceNumber: string,
     *     collectionDate: string,
     *     awbNumber: string,
     *     destination: string,
     *     amountXaf: int,
     *     status: string
     * }>
     */
    public function recentShipments(string $customerKey, int $limit, int $offset = 0): array;

    public function shipmentCount(string $customerKey): int;

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile;

    /**
     * Folds the source profile into the target, records the source name as an alias of the
     * target, and stores an undo snapshot.
     */
    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile;

    /**
     * @return list<array{
     *     id: int,
     *     targetCustomerKey: string,
     *     targetName: string,
     *     sourceName: string,
     *     mergedAt: string
     * }>
     */
    public function recentMerges(int $limit): array;

    /** Restores the merged-away profile and returns it. */
    public function undoMerge(int $mergeId, string $actorId): CustomerProfile;

    /**
     * @return list<array{
     *     pointsDelta: int,
     *     reason: string,
     *     actorId: string,
     *     createdAt: string
     * }>
     */
    public function rewardAdjustments(string $customerKey, int $limit): array;

    /**
     * @return list<array{
     *     pointsDelta: int,
     *     reason: string,
     *     actorId: string,
     *     createdAt: string
     * }>
     */
    public function rewardRedemptions(string $customerKey, int $limit, int $offset = 0): array;

    public function rewardRedemptionCount(string $customerKey): int;

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
    ): CustomerProfile;
}
