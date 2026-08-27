<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsUserAccount;
use App\Shared\Security\RecordsUserRepository;

final class DemoRecordsUserRepository implements RecordsUserRepository
{
    private const SESSION_KEY = '_demo_records_users';

    public function findActiveByUsername(string $username): ?RecordsUserAccount
    {
        foreach ($this->all() as $account) {
            if ($account->active && hash_equals($account->username, $username)) {
                return $account;
            }
        }
        return null;
    }

    public function findById(int $id): ?RecordsUserAccount
    {
        foreach ($this->all() as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }
        return null;
    }

    public function all(): array
    {
        $accounts = $_SESSION[self::SESSION_KEY] ?? [];
        return array_values(array_filter(
            is_array($accounts) ? $accounts : [],
            static fn (mixed $account): bool => $account instanceof RecordsUserAccount,
        ));
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        foreach ($this->all() as $account) {
            if ($account->id !== $exceptId && strcasecmp($account->username, $username) === 0) {
                return true;
            }
        }
        return false;
    }

    public function create(
        string $username,
        string $passwordHash,
        string $role,
        string $actorId,
    ): RecordsUserAccount {
        $accounts = $this->all();
        $now = gmdate('Y-m-d H:i:s');
        $account = new RecordsUserAccount(
            $accounts === [] ? 1 : max(array_map(static fn (RecordsUserAccount $item): int => $item->id, $accounts)) + 1,
            $username,
            $passwordHash,
            $role,
            true,
            $now,
            $now,
        );
        $accounts[] = $account;
        $_SESSION[self::SESSION_KEY] = $accounts;
        return $account;
    }

    public function update(
        int $id,
        string $username,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $actorId,
    ): ?RecordsUserAccount {
        $accounts = $this->all();
        foreach ($accounts as $index => $account) {
            if ($account->id !== $id) {
                continue;
            }

            $accounts[$index] = $account->withUpdates(
                $username,
                $role,
                $active,
                $passwordHash,
                gmdate('Y-m-d H:i:s'),
            );
            $_SESSION[self::SESSION_KEY] = $accounts;
            return $accounts[$index];
        }
        return null;
    }
}
