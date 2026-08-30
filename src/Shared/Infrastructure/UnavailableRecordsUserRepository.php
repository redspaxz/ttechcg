<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsAdminCredentialRepository;
use App\Shared\Security\RecordsUserAccount;
use App\Shared\Security\RecordsUserRepository;
use RuntimeException;

final class UnavailableRecordsUserRepository implements RecordsUserRepository, RecordsAdminCredentialRepository
{
    public function adminPasswordHash(string $username): ?string
    {
        throw new RuntimeException('Administrator credential storage is unavailable.');
    }

    public function saveAdminPasswordHash(string $username, string $passwordHash, string $actorId): void
    {
        throw new RuntimeException('Administrator credential storage is unavailable.');
    }
    public function findActiveByUsername(string $username): ?RecordsUserAccount
    {
        return null;
    }

    public function findById(int $id): ?RecordsUserAccount
    {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }

    public function all(): array
    {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }

    public function create(
        string $username,
        string $firstName,
        string $lastName,
        string $passwordHash,
        string $role,
        bool $active,
        string $actorId,
    ): RecordsUserAccount {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }

    public function update(
        int $id,
        string $username,
        string $firstName,
        string $lastName,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $actorId,
    ): ?RecordsUserAccount {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }

    public function delete(int $id, string $actorId): bool
    {
        throw new RuntimeException('Managed records-user storage is unavailable.');
    }
}
