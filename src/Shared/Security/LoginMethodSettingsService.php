<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;

final class LoginMethodSettingsService
{
    public function __construct(
        private readonly LoginMethodSettingsRepository $repository,
        private readonly bool $localLoginConfigured,
        private readonly bool $jumpCloudLoginConfigured,
        private readonly bool $cloudflareAccessConfigured,
    ) {
    }

    public function current(): LoginMethodSettings
    {
        $preferences = $this->repository->current(true, true);
        return $this->settings($preferences);
    }

    public function update(
        bool $localLoginEnabled,
        bool $jumpCloudLoginEnabled,
        string $actorId,
    ): LoginMethodSettings {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The settings actor is invalid.');
        }
        if ($localLoginEnabled && !$this->localLoginConfigured) {
            throw new InvalidArgumentException('Local login requires its .env switch, mandatory 2FA encryption key, and PHP OpenSSL support to be ready.');
        }
        if ($jumpCloudLoginEnabled && !$this->jumpCloudLoginConfigured) {
            throw new InvalidArgumentException('JumpCloud cannot be enabled until its OIDC and RBAC configuration validates.');
        }

        $effectiveLocal = $localLoginEnabled && $this->localLoginConfigured;
        $effectiveJumpCloud = $jumpCloudLoginEnabled && $this->jumpCloudLoginConfigured;
        if (!$effectiveLocal && !$effectiveJumpCloud && !$this->cloudflareAccessConfigured) {
            throw new InvalidArgumentException('Keep local login or JumpCloud enabled to avoid locking every administrator out.');
        }

        return $this->settings($this->repository->save(
            $localLoginEnabled,
            $jumpCloudLoginEnabled,
            $actorId,
        ));
    }

    /** @param array{localLoginEnabled: bool, jumpCloudLoginEnabled: bool, updatedAt: ?string} $preferences */
    private function settings(array $preferences): LoginMethodSettings
    {
        return new LoginMethodSettings(
            $this->localLoginConfigured && $preferences['localLoginEnabled'],
            $this->jumpCloudLoginConfigured && $preferences['jumpCloudLoginEnabled'],
            $this->localLoginConfigured,
            $this->jumpCloudLoginConfigured,
            $this->cloudflareAccessConfigured,
            $preferences['updatedAt'],
        );
    }
}
