<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\CollectionAgentRepository;
use RuntimeException;

final class UnavailableCollectionAgentRepository implements CollectionAgentRepository
{
    public function current(): array
    {
        return ['name' => '', 'updatedAt' => null];
    }

    public function save(string $name, string $actorId): array
    {
        throw new RuntimeException('Pickup-sheet settings storage is unavailable.');
    }
}
