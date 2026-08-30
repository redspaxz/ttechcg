<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsAdminCredentialRepository;
use App\Shared\Security\RecordsUserAccount;
use App\Shared\Security\RecordsUserRepository;
use PDO;
use PDOException;

final class MysqlRecordsUserRepository implements RecordsUserRepository, RecordsAdminCredentialRepository
{
    private bool $schemaReady = false;
    private bool $adminCredentialSchemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function adminPasswordHash(string $username): ?string
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT password_hash FROM pickup_records_admin_credentials WHERE BINARY username = :username LIMIT 1',
            );
            $statement->execute(['username' => $username]);
            $passwordHash = $statement->fetchColumn();
            return is_string($passwordHash) && $passwordHash !== '' ? $passwordHash : null;
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) {
                return null;
            }
            throw $exception;
        }
    }

    public function saveAdminPasswordHash(string $username, string $passwordHash, string $actorId): void
    {
        $this->ensureAdminCredentialSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_records_admin_credentials
                (username, password_hash, updated_by, created_at, updated_at)
             VALUES
                (:username, :password_hash, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'updated_by' => $actorId,
        ]);
    }

    public function findActiveByUsername(string $username): ?RecordsUserAccount
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT id, username, first_name, last_name, password_hash, role, active, created_at, updated_at
                 FROM pickup_records_users
                 WHERE BINARY username = :username AND active = 1
                 LIMIT 1',
            );
            $statement->execute(['username' => $username]);
            return $this->account($statement->fetch());
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1054) {
                throw $exception;
            }

            $statement = $this->connection->prepare(
                'SELECT id, username, password_hash, role, active, created_at, updated_at
                 FROM pickup_records_users
                 WHERE BINARY username = :username AND active = 1
                 LIMIT 1',
            );
            $statement->execute(['username' => $username]);
            return $this->account($statement->fetch());
        }
    }

    public function findById(int $id): ?RecordsUserAccount
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT id, username, first_name, last_name, password_hash, role, active, created_at, updated_at
             FROM pickup_records_users
             WHERE id = :id
             LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        return $this->account($statement->fetch());
    }

    public function all(): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            "SELECT id, username, first_name, last_name, password_hash, role, active, created_at, updated_at
             FROM pickup_records_users
             ORDER BY FIELD(role, 'operator', 'viewer'), username",
        );
        $statement->execute();

        $accounts = [];
        foreach ($statement->fetchAll() as $row) {
            $account = $this->account($row);
            if ($account !== null) {
                $accounts[] = $account;
            }
        }
        return $accounts;
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $this->ensureSchema();
        $sql = 'SELECT COUNT(*) FROM pickup_records_users WHERE username = :username';
        $parameters = ['username' => $username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() > 0;
    }

    public function create(
        string $username,
        string $firstName,
        string $lastName,
        string $passwordHash,
        string $role,
        bool $active,
        string $actorId,
    ): RecordsUserAccount {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_records_users
                (username, first_name, last_name, password_hash, role, active, created_by, updated_by, created_at, updated_at)
             VALUES
                (:username, :first_name, :last_name, :password_hash, :role, :active, :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $statement->execute([
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password_hash' => $passwordHash,
            'role' => $role,
            'active' => $active ? 1 : 0,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $this->findById((int) $this->connection->lastInsertId())
            ?? throw new \RuntimeException('Created account could not be loaded.');
    }

    public function update(
        int $id,
        string $username,
        string $firstName,
        string $lastName,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $actorId,
    ): ?RecordsUserAccount {
        $this->ensureSchema();
        $assignments = [
            'username = :username',
            'first_name = :first_name',
            'last_name = :last_name',
            'role = :role',
            'active = :active',
            'updated_by = :updated_by',
            'updated_at = UTC_TIMESTAMP()',
        ];
        $parameters = [
            'id' => $id,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'active' => $active ? 1 : 0,
            'updated_by' => $actorId,
        ];
        if ($passwordHash !== null) {
            $assignments[] = 'password_hash = :password_hash';
            $parameters['password_hash'] = $passwordHash;
        }

        $statement = $this->connection->prepare(
            'UPDATE pickup_records_users SET ' . implode(', ', $assignments) . ' WHERE id = :id',
        );
        $statement->execute($parameters);
        return $this->findById($id);
    }

    public function delete(int $id, string $actorId): bool
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            "DELETE FROM pickup_records_users WHERE id = :id AND role IN ('operator', 'viewer')",
        );
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    private function account(mixed $row): ?RecordsUserAccount
    {
        if (!is_array($row)) {
            return null;
        }

        return new RecordsUserAccount(
            (int) $row['id'],
            (string) $row['username'],
            $this->storedName($row['first_name'] ?? null, (string) $row['username'], true),
            $this->storedName($row['last_name'] ?? null, (string) $row['username'], false),
            (string) $row['password_hash'],
            (string) $row['role'],
            (bool) $row['active'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    private function storedName(mixed $value, string $username, bool $first): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        $parts = preg_split('/[._@-]+/', $username, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($first) {
            return substr(ucfirst(strtolower((string) ($parts[0] ?? 'Records'))), 0, 49);
        }
        return substr(ucfirst(strtolower(implode(' ', array_slice($parts, 1)))) ?: 'User', 0, 49);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        try {
            $this->connection->query('SELECT first_name, last_name FROM pickup_records_users LIMIT 1');
            $this->schemaReady = true;
            return;
        } catch (PDOException) {
            // The authenticated admin workflow may initialize the missing table below.
        }

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_records_users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                first_name VARCHAR(49) NOT NULL,
                last_name VARCHAR(49) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by CHAR(24) NOT NULL,
                updated_by CHAR(24) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE INDEX pickup_records_users_username_idx (username),
                INDEX pickup_records_users_role_active_idx (role, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        try {
            $this->connection->query('SELECT first_name, last_name FROM pickup_records_users LIMIT 1');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1054) {
                throw $exception;
            }
            $this->connection->exec(
                'ALTER TABLE pickup_records_users
                    ADD COLUMN first_name VARCHAR(49) NULL AFTER username,
                    ADD COLUMN last_name VARCHAR(49) NULL AFTER first_name',
            );
            $this->connection->exec(
                "UPDATE pickup_records_users
                 SET first_name = LEFT(username, 49), last_name = 'User'
                 WHERE first_name IS NULL OR first_name = '' OR last_name IS NULL OR last_name = ''",
            );
            $this->connection->exec(
                'ALTER TABLE pickup_records_users
                    MODIFY COLUMN first_name VARCHAR(49) NOT NULL,
                    MODIFY COLUMN last_name VARCHAR(49) NOT NULL',
            );
        }
        $this->schemaReady = true;
    }

    private function ensureAdminCredentialSchema(): void
    {
        if ($this->adminCredentialSchemaReady) {
            return;
        }

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_records_admin_credentials (
                username VARCHAR(100) PRIMARY KEY,
                password_hash VARCHAR(255) NOT NULL,
                updated_by CHAR(24) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->adminCredentialSchemaReady = true;
    }
}
