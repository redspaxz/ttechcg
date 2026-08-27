<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsUserAccount;
use App\Shared\Security\RecordsUserRepository;
use PDO;

final class MysqlRecordsUserRepository implements RecordsUserRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findActiveByUsername(string $username): ?RecordsUserAccount
    {
        $statement = $this->connection->prepare(
            'SELECT id, username, password_hash, role, active, created_at, updated_at
             FROM pickup_records_users
             WHERE BINARY username = :username AND active = 1
             LIMIT 1',
        );
        $statement->execute(['username' => $username]);
        return $this->account($statement->fetch());
    }

    public function findById(int $id): ?RecordsUserAccount
    {
        $statement = $this->connection->prepare(
            'SELECT id, username, password_hash, role, active, created_at, updated_at
             FROM pickup_records_users
             WHERE id = :id
             LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        return $this->account($statement->fetch());
    }

    public function all(): array
    {
        $statement = $this->connection->prepare(
            "SELECT id, username, password_hash, role, active, created_at, updated_at
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
        string $passwordHash,
        string $role,
        string $actorId,
    ): RecordsUserAccount {
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_records_users
                (username, password_hash, role, active, created_by, updated_by, created_at, updated_at)
             VALUES
                (:username, :password_hash, :role, 1, :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => $role,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $this->findById((int) $this->connection->lastInsertId())
            ?? throw new \RuntimeException('Created account could not be loaded.');
    }

    public function update(
        int $id,
        string $username,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $actorId,
    ): ?RecordsUserAccount {
        $assignments = [
            'username = :username',
            'role = :role',
            'active = :active',
            'updated_by = :updated_by',
            'updated_at = UTC_TIMESTAMP()',
        ];
        $parameters = [
            'id' => $id,
            'username' => $username,
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

    private function account(mixed $row): ?RecordsUserAccount
    {
        if (!is_array($row)) {
            return null;
        }

        return new RecordsUserAccount(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (string) $row['role'],
            (bool) $row['active'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
