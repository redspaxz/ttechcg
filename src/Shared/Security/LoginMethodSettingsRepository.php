<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface LoginMethodSettingsRepository
{
    /** @return array{localLoginEnabled: bool, jumpCloudLoginEnabled: bool, updatedAt: ?string} */
    public function current(bool $defaultLocal, bool $defaultJumpCloud): array;

    /** @return array{localLoginEnabled: bool, jumpCloudLoginEnabled: bool, updatedAt: ?string} */
    public function save(bool $localLoginEnabled, bool $jumpCloudLoginEnabled, string $actorId): array;
}
