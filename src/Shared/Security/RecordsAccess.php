<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class RecordsAccess
{
    public function __construct(
        private readonly string $username,
        private readonly string $passwordHash,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            trim((string) (getenv('PICKUPSHEET_RECORDS_USER') ?: '')),
            trim((string) (getenv('PICKUPSHEET_RECORDS_PASSWORD_HASH') ?: '')),
        );
    }

    public function allows(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $credentials = $request->basicCredentials();
        if ($credentials === null) {
            return false;
        }

        [$username, $password] = $credentials;

        return hash_equals($this->username, $username)
            && password_verify($password, $this->passwordHash);
    }

    public function isConfigured(): bool
    {
        return $this->username !== ''
            && $this->passwordHash !== ''
            && password_get_info($this->passwordHash)['algoName'] !== 'unknown';
    }
}
