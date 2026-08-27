<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface RecordsUserRepository
{
    public function findActiveByUsername(string $username): ?RecordsUserAccount;

    public function findById(int $id): ?RecordsUserAccount;

    /** @return list<RecordsUserAccount> */
    public function all(): array;

    public function usernameExists(string $username, ?int $exceptId = null): bool;

    public function create(
        string $username,
        string $passwordHash,
        string $role,
        string $actorId,
    ): RecordsUserAccount;

    public function update(
        int $id,
        string $username,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $actorId,
    ): ?RecordsUserAccount;
}
