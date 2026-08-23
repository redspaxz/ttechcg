<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public static function run(PDO $connection, string $directory): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files);

        $check = $connection->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
        $record = $connection->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');

        foreach ($files as $file) {
            $migration = basename($file);
            $check->execute(['migration' => $migration]);
            if ($check->fetchColumn() !== false) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration: ' . $migration);
            }

            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $connection->exec($statement);
                }
            }
            $record->execute(['migration' => $migration]);
        }
    }
}
