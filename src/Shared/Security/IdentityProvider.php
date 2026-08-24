<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface IdentityProvider
{
    public function configured(): bool;

    public function fingerprint(): string;

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string;

    /** @return array{sub: string, name: string, username: string, email: string, expires_at: int} */
    public function authenticate(string $code, string $codeVerifier, string $nonce): array;
}
