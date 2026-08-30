<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSessionActivityRepository;
use PDO;

final class MysqlRecordsSessionActivityRepository implements RecordsSessionActivityRepository
{
    private const ACTIVE_WINDOW_SECONDS = 900;
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function start(string $activityId, RecordsPrincipal $principal, int $timestamp): void
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_records_session_activity
                (activity_id, username, full_name, role, identity_provider, logged_in_at, last_seen_at)
             VALUES
                (:activity_id, :username, :full_name, :role, :identity_provider, :logged_in_at, :last_seen_at)',
        );
        $dateTime = gmdate('Y-m-d H:i:s', $timestamp);
        $statement->execute([
            'activity_id' => $activityId,
            'username' => $principal->username,
            'full_name' => $principal->fullName(),
            'role' => $principal->role,
            'identity_provider' => $principal->identityProvider,
            'logged_in_at' => $dateTime,
            'last_seen_at' => $dateTime,
        ]);
    }

    public function touch(string $activityId, int $timestamp): void
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'UPDATE pickup_records_session_activity
             SET last_seen_at = :last_seen_at
             WHERE activity_id = :activity_id AND logged_out_at IS NULL',
        );
        $statement->execute([
            'activity_id' => $activityId,
            'last_seen_at' => gmdate('Y-m-d H:i:s', $timestamp),
        ]);
    }

    public function finish(string $activityId, int $timestamp): void
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'UPDATE pickup_records_session_activity
             SET last_seen_at = :last_seen_at, logged_out_at = :logged_out_at
             WHERE activity_id = :activity_id AND logged_out_at IS NULL',
        );
        $dateTime = gmdate('Y-m-d H:i:s', $timestamp);
        $statement->execute([
            'activity_id' => $activityId,
            'last_seen_at' => $dateTime,
            'logged_out_at' => $dateTime,
        ]);
    }

    public function summary(int $days, int $limit): array
    {
        return $this->paginatedSummary($days, $limit, 0)['items'];
    }

    public function paginatedSummary(int $days, int $limit, int $offset): array
    {
        $this->ensureSchema();
        $now = time();
        $minimumDate = gmdate('Y-m-d H:i:s', $now - max(1, $days) * 86400);
        $activeAfter = gmdate('Y-m-d H:i:s', $now - self::ACTIVE_WINDOW_SECONDS);
        $countStatement = $this->connection->prepare(
            'SELECT COUNT(*) AS total_records, COALESCE(SUM(active_now), 0) AS active_records
             FROM (
                 SELECT username, identity_provider,
                        MAX(CASE WHEN logged_out_at IS NULL AND last_seen_at >= :active_after THEN 1 ELSE 0 END) AS active_now
                 FROM pickup_records_session_activity
                 WHERE logged_in_at >= :minimum_date
                 GROUP BY username, identity_provider
             ) AS activity_users',
        );
        $countStatement->execute([
            'active_after' => $activeAfter,
            'minimum_date' => $minimumDate,
        ]);
        $counts = $countStatement->fetch();
        $statement = $this->connection->prepare(
            'SELECT username,
                    MAX(full_name) AS full_name,
                    MAX(role) AS role,
                    identity_provider,
                    COUNT(*) AS login_count,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, logged_in_at, COALESCE(logged_out_at, last_seen_at)))), 0) AS total_session_seconds,
                    COALESCE(ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, logged_in_at, COALESCE(logged_out_at, last_seen_at))))), 0) AS average_session_seconds,
                    MAX(logged_in_at) AS last_login_at,
                    MAX(CASE WHEN logged_out_at IS NULL AND last_seen_at >= :active_after THEN 1 ELSE 0 END) AS active_now
             FROM pickup_records_session_activity
             WHERE logged_in_at >= :minimum_date
             GROUP BY username, identity_provider
             ORDER BY active_now DESC, login_count DESC, last_login_at DESC
             LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':active_after', $activeAfter);
        $statement->bindValue(':minimum_date', $minimumDate);
        $statement->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(static fn (array $row): array => [
                'username' => (string) $row['username'],
                'fullName' => (string) $row['full_name'],
                'role' => (string) $row['role'],
                'identityProvider' => (string) $row['identity_provider'],
                'loginCount' => (int) $row['login_count'],
                'totalSessionSeconds' => (int) $row['total_session_seconds'],
                'averageSessionSeconds' => (int) $row['average_session_seconds'],
                'lastLoginAt' => (string) $row['last_login_at'],
                'activeNow' => (bool) $row['active_now'],
            ], $statement->fetchAll()),
            'totalRecords' => (int) ($counts['total_records'] ?? 0),
            'activeRecords' => (int) ($counts['active_records'] ?? 0),
        ];
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_records_session_activity (
                activity_id CHAR(32) PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role VARCHAR(20) NOT NULL,
                identity_provider VARCHAR(32) NOT NULL,
                logged_in_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                logged_out_at DATETIME NULL,
                INDEX pickup_records_session_user_login_idx (username, logged_in_at),
                INDEX pickup_records_session_active_idx (logged_out_at, last_seen_at),
                INDEX pickup_records_session_login_idx (logged_in_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->schemaReady = true;
    }
}
