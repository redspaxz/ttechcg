<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

interface CustomerRepository
{
    public function synchronizeFromShipments(): void;

    /**
     * Filters: search (name, alias, contact, email, phone, city), status, followUp ('' | due |
     * scheduled | none), owner ('' | unassigned | a 24-character actor id), and sort (priority |
     * name | last_shipment | value | points).
     *
     * @param array{search: string, status: string, followUp: string, owner: string, sort: string} $filters
     * @return array{items: list<CustomerProfile>, totalRecords: int}
     */
    public function paginated(array $filters, int $limit, int $offset): array;

    /** @return array{customerCount: int, activeCount: int, attentionCount: int, followUpsDue: int} */
    public function summary(): array;

    /** @return list<CustomerProfile> */
    public function topByRewardPoints(int $limit): array;

    /** @return list<string> */
    public function suggestions(string $query, int $limit): array;

    /**
     * Lightweight name index for duplicate detection across every profile.
     *
     * @return list<array{customerKey: string, displayName: string, email: string, phone: string}>
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

    /**
     * Rejects the save when $expectedUpdatedAt is given and the stored profile has changed since,
     * so one editor cannot silently overwrite another.
     */
    public function save(CustomerProfile $customer, string $actorId, ?string $expectedUpdatedAt = null): CustomerProfile;

    /**
     * Erases the profile with its contact history, reward adjustments, aliases, and merge snapshots.
     * Pickup sheets keep the consignor name as part of the operational record.
     */
    public function delete(string $customerKey, string $actorId): void;

    /**
     * @return list<array{type: string, occurredOn: string, summary: string, actorId: string, actorName: string, createdAt: string}>
     */
    public function activities(string $customerKey, int $limit): array;

    /** Records a contact activity and sets (or clears) the next follow-up date in one step. */
    public function addActivity(
        string $customerKey,
        string $type,
        string $occurredOn,
        string $summary,
        ?string $nextFollowUpOn,
        string $actorId,
        string $actorName,
    ): CustomerProfile;

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
