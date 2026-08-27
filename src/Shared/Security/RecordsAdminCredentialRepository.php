<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface RecordsAdminCredentialRepository
{
    public function adminPasswordHash(string $username): ?string;

    public function saveAdminPasswordHash(string $username, string $passwordHash, string $actorId): void;
}
