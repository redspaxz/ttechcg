<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface SecurityEventRepository
{
    /** @param array<string, bool|float|int|string|null> $context */
    public function record(
        string $eventName,
        string $outcome,
        string $requestId,
        string $clientId,
        string $method,
        string $path,
        string $occurredAt,
        array $context,
    ): void;

    /**
     * @return list<array{
     *     eventName: string,
     *     outcome: string,
     *     actorId: string,
     *     targetId: string,
     *     resourceId: string,
     *     role: string,
     *     identityProvider: string,
     *     requestId: string,
     *     clientId: string,
     *     method: string,
     *     path: string,
     *     occurredAt: string,
     *     context: array<string, bool|float|int|string|null>
     * }>
     */
    public function recentPickupsheet(int $limit): array;

    /** @return array{items: list<array<string, mixed>>, totalRecords: int} */
    public function paginatedPickupsheet(int $limit, int $offset): array;
}
