<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LoginMethodSettingsRepository;

final class DemoLoginMethodSettingsRepository implements LoginMethodSettingsRepository
{
    private const SESSION_KEY = '_demo_login_method_settings';

    public function current(bool $defaultLocal, bool $defaultJumpCloud): array
    {
        $settings = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($settings)) {
            return [
                'localLoginEnabled' => $defaultLocal,
                'jumpCloudLoginEnabled' => $defaultJumpCloud,
                'updatedAt' => null,
            ];
        }
        return [
            'localLoginEnabled' => (bool) ($settings['localLoginEnabled'] ?? $defaultLocal),
            'jumpCloudLoginEnabled' => (bool) ($settings['jumpCloudLoginEnabled'] ?? $defaultJumpCloud),
            'updatedAt' => is_string($settings['updatedAt'] ?? null) ? $settings['updatedAt'] : null,
        ];
    }

    public function save(bool $localLoginEnabled, bool $jumpCloudLoginEnabled, string $actorId): array
    {
        $settings = [
            'localLoginEnabled' => $localLoginEnabled,
            'jumpCloudLoginEnabled' => $jumpCloudLoginEnabled,
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ];
        $_SESSION[self::SESSION_KEY] = $settings;
        return $settings;
    }
}
