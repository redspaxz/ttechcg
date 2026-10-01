<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\CollectionAgentRepository;
use PDO;
use PDOException;

final class MysqlCollectionAgentRepository implements CollectionAgentRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function current(): array
    {
        try {
            $row = $this->connection->query(
                'SELECT collection_agent_name, updated_at FROM pickup_sheet_settings WHERE settings_id = 1 LIMIT 1',
            )->fetch();
        } catch (PDOException $exception) {
            // Before migration 025 runs there is no settings table, which means no agent has been assigned yet.
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) {
                return ['name' => '', 'updatedAt' => null];
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return ['name' => '', 'updatedAt' => null];
        }
        return [
            'name' => (string) ($row['collection_agent_name'] ?? ''),
            'updatedAt' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
    }

    public function save(string $name, string $actorId): array
    {
        $this->ensureSchema();
        $this->connection->prepare(
            'INSERT INTO pickup_sheet_settings (settings_id, collection_agent_name, updated_by, created_at, updated_at)
             VALUES (1, :name, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                collection_agent_name = VALUES(collection_agent_name),
                updated_by = VALUES(updated_by),
                updated_at = UTC_TIMESTAMP()',
        )->execute(['name' => $name, 'updated_by' => $actorId]);
        return $this->current();
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->connection->exec(
            "CREATE TABLE IF NOT EXISTS pickup_sheet_settings (
                settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                collection_agent_name VARCHAR(100) NOT NULL DEFAULT '',
                updated_by CHAR(24) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
        $this->schemaReady = true;
    }
}
