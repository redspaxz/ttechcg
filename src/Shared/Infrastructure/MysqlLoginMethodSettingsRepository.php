<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\LoginMethodSettingsRepository;
use PDO;
use PDOException;

final class MysqlLoginMethodSettingsRepository implements LoginMethodSettingsRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function current(bool $defaultLocal, bool $defaultJumpCloud): array
    {
        try {
            $row = $this->connection->query(
                'SELECT local_login_enabled, jumpcloud_login_enabled, updated_at
                 FROM pickup_auth_settings WHERE settings_id = 1 LIMIT 1',
            )->fetch();
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) {
                return $this->defaults($defaultLocal, $defaultJumpCloud);
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return $this->defaults($defaultLocal, $defaultJumpCloud);
        }
        return [
            'localLoginEnabled' => (bool) $row['local_login_enabled'],
            'jumpCloudLoginEnabled' => (bool) $row['jumpcloud_login_enabled'],
            'updatedAt' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
    }

    public function save(bool $localLoginEnabled, bool $jumpCloudLoginEnabled, string $actorId): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_auth_settings
                (settings_id, local_login_enabled, jumpcloud_login_enabled, updated_by, created_at, updated_at)
             VALUES (1, :local_login_enabled, :jumpcloud_login_enabled, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                local_login_enabled = VALUES(local_login_enabled),
                jumpcloud_login_enabled = VALUES(jumpcloud_login_enabled),
                updated_by = VALUES(updated_by),
                updated_at = UTC_TIMESTAMP()',
        );
        $statement->execute([
            'local_login_enabled' => $localLoginEnabled ? 1 : 0,
            'jumpcloud_login_enabled' => $jumpCloudLoginEnabled ? 1 : 0,
            'updated_by' => $actorId,
        ]);
        return $this->current($localLoginEnabled, $jumpCloudLoginEnabled);
    }

    /** @return array{localLoginEnabled: bool, jumpCloudLoginEnabled: bool, updatedAt: null} */
    private function defaults(bool $defaultLocal, bool $defaultJumpCloud): array
    {
        return [
            'localLoginEnabled' => $defaultLocal,
            'jumpCloudLoginEnabled' => $defaultJumpCloud,
            'updatedAt' => null,
        ];
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_auth_settings (
                settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                local_login_enabled TINYINT(1) NOT NULL DEFAULT 1,
                jumpcloud_login_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by CHAR(24) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->schemaReady = true;
    }
}
