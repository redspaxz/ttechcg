<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface LocalMfaRepository
{
    public function find(string $subjectId): ?LocalMfaEnrollment;

    /** @param list<string> $recoveryCodeHashes */
    public function save(
        string $subjectId,
        string $username,
        string $secretEnvelope,
        array $recoveryCodeHashes,
        string $actorId,
    ): void;

    public function claimTotpStep(string $subjectId, int $step): bool;

    public function consumeRecoveryCodeHash(string $subjectId, string $recoveryCodeHash): bool;

    public function delete(string $subjectId, string $actorId): bool;

    /** @param list<string> $subjectIds @return array<string, bool> */
    public function statuses(array $subjectIds): array;
}
