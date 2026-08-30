<?php

declare(strict_types=1);

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Domain\BackupRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class MysqlBackupRepository implements BackupRepository
{
    /** Parent tables precede their dependent tables for restore inserts. */
    private const TABLES = [
        'inquiries',
        'pickup_sheets',
        'pickup_shipments',
        'pickup_sheet_edit_audit',
        'pickup_sheet_lifecycle_audit',
        'pickup_records_users',
        'pickup_records_admin_credentials',
        'pickup_auth_settings',
        'pickup_records_session_activity',
        'pickup_security_events',
        'pickup_customers',
        'pickup_customer_reward_adjustments',
    ];
    private const MAX_ROWS = 250000;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function exportTables(): array
    {
        $this->connection->beginTransaction();
        try {
            $tables = [];
            $rowCount = 0;
            foreach (self::TABLES as $table) {
                $columns = $this->columns($table);
                $columnSql = implode(', ', array_map($this->quoteIdentifier(...), $columns));
                $rows = $this->connection->query('SELECT ' . $columnSql . ' FROM ' . $this->quoteIdentifier($table))->fetchAll();
                $rowCount += count($rows);
                if ($rowCount > self::MAX_ROWS) {
                    throw new RuntimeException('The database contains too many rows for the application backup limit.');
                }
                $tables[$table] = ['columns' => $columns, 'rows' => $rows];
            }
            $this->connection->commit();
            return $tables;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('A consistent database snapshot could not be created.', 0, $exception);
        }
    }

    public function restoreTables(array $tables): array
    {
        $expectedTables = self::TABLES;
        $suppliedTables = array_keys($tables);
        sort($expectedTables);
        sort($suppliedTables);
        if ($suppliedTables !== $expectedTables) {
            throw new InvalidArgumentException('The backup does not match the required Pickupsheet data set.');
        }

        $validated = [];
        $totalRows = 0;
        foreach (self::TABLES as $table) {
            $backupTable = $tables[$table] ?? null;
            if (!is_array($backupTable) || !is_array($backupTable['columns'] ?? null) || !is_array($backupTable['rows'] ?? null)) {
                throw new InvalidArgumentException('The backup table structure is invalid.');
            }
            $columns = array_values($backupTable['columns']);
            if ($columns !== $this->columns($table)) {
                throw new InvalidArgumentException('The backup schema does not match the current application schema.');
            }
            $expectedKeys = $columns;
            sort($expectedKeys);
            $rows = array_values($backupTable['rows']);
            $totalRows += count($rows);
            if ($totalRows > self::MAX_ROWS) {
                throw new InvalidArgumentException('The backup contains too many rows.');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('The backup contains an invalid row.');
                }
                $keys = array_keys($row);
                sort($keys);
                if ($keys !== $expectedKeys) {
                    throw new InvalidArgumentException('The backup row does not match its table schema.');
                }
                foreach ($row as $value) {
                    if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                        throw new InvalidArgumentException('The backup contains an unsupported value.');
                    }
                }
            }
            $validated[$table] = ['columns' => $columns, 'rows' => $rows];
        }

        $this->connection->beginTransaction();
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                $this->connection->exec('DELETE FROM ' . $this->quoteIdentifier($table));
            }
            $counts = [];
            foreach (self::TABLES as $table) {
                $columns = $validated[$table]['columns'];
                $rows = $validated[$table]['rows'];
                $counts[$table] = count($rows);
                if ($rows === []) {
                    continue;
                }
                $columnSql = implode(', ', array_map($this->quoteIdentifier(...), $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $statement = $this->connection->prepare(
                    'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $columnSql . ') VALUES (' . $placeholders . ')',
                );
                foreach ($rows as $row) {
                    $statement->execute(array_map(static fn (string $column): mixed => $row[$column], $columns));
                }
            }
            $this->connection->commit();
            return $counts;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw new RuntimeException('The backup could not be restored transactionally.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        try {
            $rows = $this->connection->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table))->fetchAll();
        } catch (Throwable $exception) {
            throw new RuntimeException('Required backup table is unavailable: ' . $table, 0, $exception);
        }
        $columns = array_values(array_filter(array_map(
            static fn (array $row): string => is_string($row['Field'] ?? null) ? $row['Field'] : '',
            $rows,
        )));
        if ($columns === []) {
            throw new RuntimeException('Required backup table is unavailable: ' . $table);
        }
        return $columns;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $identifier) !== 1) {
            throw new RuntimeException('Invalid database identifier.');
        }
        return '`' . $identifier . '`';
    }
}
