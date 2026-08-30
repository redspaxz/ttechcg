<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LoginMethodSettingsRepository;
use RuntimeException;

final class UnavailableLoginMethodSettingsRepository implements LoginMethodSettingsRepository
{
    public function current(bool $defaultLocal, bool $defaultJumpCloud): array
    {
        return [
            'localLoginEnabled' => $defaultLocal,
            'jumpCloudLoginEnabled' => $defaultJumpCloud,
            'updatedAt' => null,
        ];
    }

    public function save(bool $localLoginEnabled, bool $jumpCloudLoginEnabled, string $actorId): array
    {
        throw new RuntimeException('Login-method settings storage is unavailable.');
    }
}
