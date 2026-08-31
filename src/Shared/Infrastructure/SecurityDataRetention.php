<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

final class SecurityDataRetention
{
    private const RUN_INTERVAL_SECONDS = 86400;

    public function __construct(
        private readonly PDO $connection,
        private readonly string $stateDirectory,
        private readonly int $eventRetentionDays,
        private readonly int $sessionRetentionDays,
    ) {
    }

    /** @return array{events: int, sessions: int}|null */
    public function runIfDue(): ?array
    {
        if (!is_dir($this->stateDirectory) || !is_writable($this->stateDirectory)) {
            throw new RuntimeException('Security retention state storage is unavailable.');
        }
        $statePath = rtrim($this->stateDirectory, '/\\') . DIRECTORY_SEPARATOR . 'retention.state';
        $handle = fopen($statePath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Security retention state could not be locked.');
        }

        try {
            rewind($handle);
            $lastRun = (int) trim((string) stream_get_contents($handle));
            $now = time();
            if ($lastRun > $now - self::RUN_INTERVAL_SECONDS) {
                return null;
            }

            $results = [
                'events' => $this->deleteOlderThan(
                    'DELETE FROM pickup_security_events WHERE occurred_at < :cutoff',
                    $now - $this->days($this->eventRetentionDays) * 86400,
                ),
                'sessions' => $this->deleteOlderThan(
                    'DELETE FROM pickup_records_session_activity WHERE last_seen_at < :cutoff',
                    $now - $this->days($this->sessionRetentionDays) * 86400,
                ),
            ];

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, (string) $now) === false || !fflush($handle)) {
                throw new RuntimeException('Security retention state could not be saved.');
            }
            return $results;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function deleteOlderThan(string $query, int $cutoff): int
    {
        try {
            $statement = $this->connection->prepare($query);
            $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', $cutoff)]);
            return $statement->rowCount();
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) {
                return 0;
            }
            throw $exception;
        }
    }

    private function days(int $value): int
    {
        return max(30, min($value, 3650));
    }
}
