<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

/** Stores the collection agent name an administrator assigns to every new pickup sheet. */
interface CollectionAgentRepository
{
    /** @return array{name: string, updatedAt: ?string} An empty name means none has been assigned. */
    public function current(): array;

    /** @return array{name: string, updatedAt: ?string} */
    public function save(string $name, string $actorId): array;
}
