<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;
use RuntimeException;

final class RecordsUserService
{
    private const MANAGED_ROLES = ['viewer', 'operator'];

    /** @var array<string, true> */
    private array $reservedUsernames = [];

    /** @param list<string> $reservedUsernames */
    public function __construct(
        private readonly RecordsUserRepository $repository,
        array $reservedUsernames = [],
    ) {
        foreach ($reservedUsernames as $username) {
            $this->reservedUsernames[strtolower($username)] = true;
        }
    }

    /** @return list<RecordsUserAccount> */
    public function accounts(RecordsPrincipal $actor): array
    {
        $this->assertAdmin($actor);
        return array_values(array_filter(
            $this->repository->all(),
            static fn (RecordsUserAccount $account): bool => in_array($account->role, self::MANAGED_ROLES, true),
        ));
    }

    /** @param array<string, string> $input */
    public function create(array $input, RecordsPrincipal $actor): RecordsUserAccount
    {
        $this->assertAdmin($actor);
        $username = $this->username($input['username'] ?? '');
        $role = $this->managedRole($input['role'] ?? '');
        $password = $this->password($input['password'] ?? '', $input['password_confirmation'] ?? '');
        $this->assertUsernameAvailable($username);

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to secure the account password.');
        }

        return $this->repository->create($username, $passwordHash, $role, $this->actorId($actor));
    }

    /** @param array<string, string> $input */
    public function update(array $input, RecordsPrincipal $actor): RecordsUserAccount
    {
        $this->assertAdmin($actor);
        $id = filter_var($input['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('Select a valid managed account.');
        }

        $account = $this->repository->findById($id);
        if ($account === null || !in_array($account->role, self::MANAGED_ROLES, true)) {
            throw new InvalidArgumentException('Only lower-tier accounts can be adjusted.');
        }

        $username = $this->username($input['username'] ?? '');
        $role = $this->managedRole($input['role'] ?? '');
        $this->assertUsernameAvailable($username, $id);

        $passwordHash = null;
        $password = $input['password'] ?? '';
        $confirmation = $input['password_confirmation'] ?? '';
        if ($password !== '' || $confirmation !== '') {
            $passwordHash = password_hash($this->password($password, $confirmation), PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Unable to secure the account password.');
            }
        }

        $updated = $this->repository->update(
            $id,
            $username,
            $role,
            ($input['active'] ?? '') === '1',
            $passwordHash,
            $this->actorId($actor),
        );

        if ($updated === null) {
            throw new InvalidArgumentException('The managed account no longer exists.');
        }

        return $updated;
    }

    private function assertAdmin(RecordsPrincipal $actor): void
    {
        if (!$actor->can('manage')) {
            throw new InvalidArgumentException('Administrator access is required.');
        }
    }

    private function username(string $username): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z0-9][a-z0-9._@-]{2,99}$/', $username) !== 1) {
            throw new InvalidArgumentException('Username must be 3–100 characters and use only letters, numbers, dots, underscores, @, or hyphens.');
        }

        return $username;
    }

    private function managedRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::MANAGED_ROLES, true)) {
            throw new InvalidArgumentException('Managed accounts must be assigned the viewer or operator role.');
        }

        return $role;
    }

    private function password(string $password, string $confirmation): string
    {
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('Password confirmation does not match.');
        }
        if (strlen($password) < 12 || strlen($password) > 128) {
            throw new InvalidArgumentException('Password must contain between 12 and 128 characters.');
        }

        return $password;
    }

    private function assertUsernameAvailable(string $username, ?int $exceptId = null): void
    {
        if (isset($this->reservedUsernames[strtolower($username)])) {
            throw new InvalidArgumentException('That username is reserved by a server-managed account.');
        }
        if ($this->repository->usernameExists($username, $exceptId)) {
            throw new InvalidArgumentException('That username is already in use.');
        }
    }

    private function actorId(RecordsPrincipal $actor): string
    {
        return substr(hash('sha256', $actor->username), 0, 24);
    }
}
