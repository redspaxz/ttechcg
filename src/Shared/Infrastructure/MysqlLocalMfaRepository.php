<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LocalMfaEnrollment;
use App\Shared\Security\LocalMfaRepository;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MysqlLocalMfaRepository implements LocalMfaRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function find(string $subjectId): ?LocalMfaEnrollment
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT subject_id, username, secret_envelope, recovery_code_hashes, last_used_step, enabled_at
                 FROM pickup_local_mfa WHERE subject_id = :subject_id LIMIT 1',
            );
            $statement->execute(['subject_id' => $subjectId]);
            return $this->enrollment($statement->fetch());
        } catch (PDOException $exception) {
            if ($this->missingTable($exception)) {
                return null;
            }
            throw $exception;
        }
    }

    public function save(
        string $subjectId,
        string $username,
        string $secretEnvelope,
        array $recoveryCodeHashes,
        string $actorId,
    ): void {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_local_mfa
                (subject_id, username, secret_envelope, recovery_code_hashes, last_used_step, enabled_at, updated_by, updated_at)
             VALUES
                (:subject_id, :username, :secret_envelope, :recovery_code_hashes, NULL, UTC_TIMESTAMP(), :updated_by, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                username = VALUES(username), secret_envelope = VALUES(secret_envelope),
                recovery_code_hashes = VALUES(recovery_code_hashes), last_used_step = NULL,
                enabled_at = UTC_TIMESTAMP(), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
        );
        $statement->execute([
            'subject_id' => $subjectId,
            'username' => $username,
            'secret_envelope' => $secretEnvelope,
            'recovery_code_hashes' => json_encode(array_values($recoveryCodeHashes), JSON_THROW_ON_ERROR),
            'updated_by' => $actorId,
        ]);
    }

    public function claimTotpStep(string $subjectId, int $step): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE pickup_local_mfa
             SET last_used_step = :step, updated_at = UTC_TIMESTAMP()
             WHERE subject_id = :subject_id AND (last_used_step IS NULL OR last_used_step < :step)',
        );
        $statement->execute(['subject_id' => $subjectId, 'step' => $step]);
        return $statement->rowCount() === 1;
    }

    public function consumeRecoveryCodeHash(string $subjectId, string $recoveryCodeHash): bool
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT recovery_code_hashes FROM pickup_local_mfa WHERE subject_id = :subject_id FOR UPDATE',
            );
            $statement->execute(['subject_id' => $subjectId]);
            $encoded = $statement->fetchColumn();
            $hashes = is_string($encoded) ? json_decode($encoded, true, 32, JSON_THROW_ON_ERROR) : null;
            if (!is_array($hashes)) {
                $this->connection->rollBack();
                return false;
            }
            $remaining = [];
            $matched = false;
            foreach ($hashes as $storedHash) {
                if (is_string($storedHash) && !$matched && hash_equals($storedHash, $recoveryCodeHash)) {
                    $matched = true;
                    continue;
                }
                if (is_string($storedHash)) {
                    $remaining[] = $storedHash;
                }
            }
            if (!$matched) {
                $this->connection->rollBack();
                return false;
            }
            $update = $this->connection->prepare(
                'UPDATE pickup_local_mfa SET recovery_code_hashes = :hashes, updated_at = UTC_TIMESTAMP() WHERE subject_id = :subject_id',
            );
            $update->execute([
                'hashes' => json_encode($remaining, JSON_THROW_ON_ERROR),
                'subject_id' => $subjectId,
            ]);
            $this->connection->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(string $subjectId, string $actorId): bool
    {
        try {
            $statement = $this->connection->prepare('DELETE FROM pickup_local_mfa WHERE subject_id = :subject_id');
            $statement->execute(['subject_id' => $subjectId]);
            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            if ($this->missingTable($exception)) {
                return false;
            }
            throw $exception;
        }
    }

    public function statuses(array $subjectIds): array
    {
        $statuses = array_fill_keys($subjectIds, false);
        if ($subjectIds === []) {
            return $statuses;
        }
        $placeholders = implode(', ', array_fill(0, count($subjectIds), '?'));
        try {
            $statement = $this->connection->prepare(
                'SELECT subject_id FROM pickup_local_mfa WHERE subject_id IN (' . $placeholders . ')',
            );
            $statement->execute(array_values($subjectIds));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $subjectId) {
                if (is_string($subjectId) && array_key_exists($subjectId, $statuses)) {
                    $statuses[$subjectId] = true;
                }
            }
            return $statuses;
        } catch (PDOException $exception) {
            if ($this->missingTable($exception)) {
                return $statuses;
            }
            throw $exception;
        }
    }

    private function enrollment(mixed $row): ?LocalMfaEnrollment
    {
        if (!is_array($row)) {
            return null;
        }
        $hashes = json_decode((string) $row['recovery_code_hashes'], true);
        if (!is_array($hashes)) {
            throw new RuntimeException('Stored 2FA recovery codes are invalid.');
        }
        return new LocalMfaEnrollment(
            (string) $row['subject_id'],
            (string) $row['username'],
            (string) $row['secret_envelope'],
            array_values(array_filter($hashes, 'is_string')),
            $row['last_used_step'] === null ? null : (int) $row['last_used_step'],
            (string) $row['enabled_at'],
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_local_mfa (
                subject_id VARCHAR(128) NOT NULL PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                secret_envelope TEXT NOT NULL,
                recovery_code_hashes LONGTEXT NOT NULL,
                last_used_step BIGINT NULL,
                enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by CHAR(24) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX pickup_local_mfa_username_idx (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->schemaReady = true;
    }

    private function missingTable(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '42S02' || (int) ($exception->errorInfo[1] ?? 0) === 1146;
    }
}
