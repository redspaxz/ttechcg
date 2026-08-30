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

    public function find(string $customerKey): ?CustomerProfile;

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
    public function recentShipments(string $customerKey, int $limit): array;

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile;

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
    public function rewardRedemptions(string $customerKey, int $limit): array;

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
    ): CustomerProfile;
}
