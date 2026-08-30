<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class LoginMethodSettings
{
    public function __construct(
        public readonly bool $localLoginEnabled,
        public readonly bool $jumpCloudLoginEnabled,
        public readonly bool $localLoginConfigured,
        public readonly bool $jumpCloudLoginConfigured,
        public readonly bool $cloudflareAccessConfigured,
        public readonly ?string $updatedAt = null,
    ) {
    }
}
