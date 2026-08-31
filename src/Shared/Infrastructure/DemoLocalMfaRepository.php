<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LocalMfaEnrollment;
use App\Shared\Security\LocalMfaRepository;

final class DemoLocalMfaRepository implements LocalMfaRepository
{
    private const SESSION_KEY = '_demo_local_mfa';

    public function find(string $subjectId): ?LocalMfaEnrollment
    {
        $records = $_SESSION[self::SESSION_KEY] ?? [];
        $record = is_array($records) ? ($records[$subjectId] ?? null) : null;
        return $record instanceof LocalMfaEnrollment ? $record : null;
    }

    public function save(
        string $subjectId,
        string $username,
        string $secretEnvelope,
        array $recoveryCodeHashes,
        string $actorId,
    ): void {
        $records = $_SESSION[self::SESSION_KEY] ?? [];
        $records = is_array($records) ? $records : [];
        $records[$subjectId] = new LocalMfaEnrollment(
            $subjectId,
            $username,
            $secretEnvelope,
            array_values($recoveryCodeHashes),
            null,
            gmdate('Y-m-d H:i:s'),
        );
        $_SESSION[self::SESSION_KEY] = $records;
    }

    public function claimTotpStep(string $subjectId, int $step): bool
    {
        $record = $this->find($subjectId);
        if ($record === null || ($record->lastUsedStep !== null && $record->lastUsedStep >= $step)) {
            return false;
        }
        $this->replace($record, $record->recoveryCodeHashes, $step);
        return true;
    }

    public function consumeRecoveryCodeHash(string $subjectId, string $recoveryCodeHash): bool
    {
        $record = $this->find($subjectId);
        if ($record === null) {
            return false;
        }
        $remaining = [];
        $matched = false;
        foreach ($record->recoveryCodeHashes as $storedHash) {
            if (!$matched && hash_equals($storedHash, $recoveryCodeHash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $storedHash;
        }
        if (!$matched) {
            return false;
        }
        $this->replace($record, $remaining, $record->lastUsedStep);
        return true;
    }

    public function delete(string $subjectId, string $actorId): bool
    {
        $records = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($records) || !isset($records[$subjectId])) {
            return false;
        }
        unset($records[$subjectId]);
        $_SESSION[self::SESSION_KEY] = $records;
        return true;
    }

    public function statuses(array $subjectIds): array
    {
        $statuses = [];
        foreach ($subjectIds as $subjectId) {
            $statuses[$subjectId] = $this->find($subjectId) !== null;
        }
        return $statuses;
    }

    /** @param list<string> $recoveryCodeHashes */
    private function replace(LocalMfaEnrollment $record, array $recoveryCodeHashes, ?int $lastUsedStep): void
    {
        $records = $_SESSION[self::SESSION_KEY] ?? [];
        $records = is_array($records) ? $records : [];
        $records[$record->subjectId] = new LocalMfaEnrollment(
            $record->subjectId,
            $record->username,
            $record->secretEnvelope,
            $recoveryCodeHashes,
            $lastUsedStep,
            $record->enabledAt,
        );
        $_SESSION[self::SESSION_KEY] = $records;
    }
}
