<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Application;

use App\Modules\Pickupsheet\Domain\CollectionAgentRepository;
use App\Shared\Text\NameText;
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
        $name = NameText::normalize($name);
        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Enter a collection agent name of 2 to 100 characters.');
        }
        if (!NameText::isAllowed($name)) {
            throw new InvalidArgumentException('The collection agent name ' . NameText::RULE . '.');
        }
        return $this->repository->save($name, $actorId);
    }
}
