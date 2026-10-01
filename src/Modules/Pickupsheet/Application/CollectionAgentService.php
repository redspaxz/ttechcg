<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Application;

use App\Modules\Pickupsheet\Domain\CollectionAgentRepository;
use InvalidArgumentException;

/**
 * The collection agent name is assigned by an administrator and stamped on every new pickup sheet.
 * Sheet creators cannot type or change it.
 */
final class CollectionAgentService
{
    public function __construct(private readonly CollectionAgentRepository $repository)
    {
    }

    /** The assigned name, or an empty string when an administrator has not assigned one yet. */
    public function name(): string
    {
        return $this->repository->current()['name'];
    }

    /** @return array{name: string, updatedAt: ?string} */
    public function current(): array
    {
        return $this->repository->current();
    }

    /** @return array{name: string, updatedAt: ?string} */
    public function assign(string $name, string $actorId): array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The administrator identity is invalid.');
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Enter a collection agent name of 2 to 100 characters.');
        }
        return $this->repository->save($name, $actorId);
    }
}
