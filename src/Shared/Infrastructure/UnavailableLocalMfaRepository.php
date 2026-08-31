<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LocalMfaEnrollment;
use App\Shared\Security\LocalMfaRepository;
use RuntimeException;

final class UnavailableLocalMfaRepository implements LocalMfaRepository
{
    public function find(string $subjectId): ?LocalMfaEnrollment
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }

    public function save(string $subjectId, string $username, string $secretEnvelope, array $recoveryCodeHashes, string $actorId): void
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }

    public function claimTotpStep(string $subjectId, int $step): bool
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }

    public function consumeRecoveryCodeHash(string $subjectId, string $recoveryCodeHash): bool
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }

    public function delete(string $subjectId, string $actorId): bool
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }

    public function statuses(array $subjectIds): array
    {
        throw new RuntimeException('Local 2FA storage is unavailable.');
    }
}
