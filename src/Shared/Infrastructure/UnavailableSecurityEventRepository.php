<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\SecurityEventRepository;

final class UnavailableSecurityEventRepository implements SecurityEventRepository
{
    public function record(
        string $eventName,
        string $outcome,
        string $requestId,
        string $clientId,
        string $method,
        string $path,
        string $occurredAt,
        array $context,
    ): void {
    }

    public function recentPickupsheet(int $limit): array
    {
        return [];
    }
}
