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
    private readonly ?RecordsAdminCredentialRepository $adminCredentialRepository;

    /** @param list<string> $reservedUsernames */
    public function __construct(
        private readonly RecordsUserRepository $repository,
        array $reservedUsernames = [],
        ?RecordsAdminCredentialRepository $adminCredentialRepository = null,
    ) {
        $this->adminCredentialRepository = $adminCredentialRepository
            ?? ($repository instanceof RecordsAdminCredentialRepository ? $repository : null);
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

    public function account(string $accountId, RecordsPrincipal $actor): RecordsUserAccount
    {
        $this->assertAdmin($actor);
        $id = filter_var($accountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('Select a valid managed account.');
        }
        $account = $this->repository->findById($id);
        if ($account === null || !in_array($account->role, self::MANAGED_ROLES, true)) {
            throw new InvalidArgumentException('Only local operator and viewer accounts can be managed.');
        }
        return $account;
    }

    /** @param array<string, string> $input */
    public function create(array $input, RecordsPrincipal $actor): RecordsUserAccount
    {
        $this->assertAdmin($actor);
        $username = $this->username($input['username'] ?? '');
        $firstName = $this->name($input['first_name'] ?? '', 'First name');
        $lastName = $this->name($input['last_name'] ?? '', 'Last name');
        $role = $this->managedRole($input['role'] ?? '');
        $active = $this->accountStatus($input['active'] ?? '');
        $password = $this->password($input['password'] ?? '', $input['password_confirmation'] ?? '');
        $this->assertUsernameAvailable($username);

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to secure the account password.');
        }

        return $this->repository->create(
            $username,
            $firstName,
            $lastName,
            $passwordHash,
            $role,
            $active,
            $this->actorId($actor),
        );
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
        $firstName = $this->name($input['first_name'] ?? '', 'First name');
        $lastName = $this->name($input['last_name'] ?? '', 'Last name');
        $role = $this->managedRole($input['role'] ?? '');
        $active = $this->accountStatus($input['active'] ?? '');
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
            $firstName,
            $lastName,
            $role,
            $active,
            $passwordHash,
            $this->actorId($actor),
        );

        if ($updated === null) {
            throw new InvalidArgumentException('The managed account no longer exists.');
        }

        return $updated;
    }

    public function delete(string $accountId, RecordsPrincipal $actor): RecordsUserAccount
    {
        $this->assertAdmin($actor);
        $id = filter_var($accountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('Select a valid managed account.');
        }

        $account = $this->repository->findById($id);
        if ($account === null || !in_array($account->role, self::MANAGED_ROLES, true)) {
            throw new InvalidArgumentException('Only local operator and viewer accounts can be deleted.');
        }
        if (!$this->repository->delete($id, $this->actorId($actor))) {
            throw new InvalidArgumentException('The managed account no longer exists.');
        }

        return $account;
    }

    /** @param array<string, string> $input */
    public function resetAdministratorPassword(array $input, RecordsPrincipal $actor): void
    {
        $this->assertAdmin($actor);
        if (!isset($this->reservedUsernames[strtolower($actor->username)])) {
            throw new InvalidArgumentException('Only a server-defined administrator can reset an administrator password.');
        }
        if ($this->adminCredentialRepository === null) {
            throw new RuntimeException('Administrator credential storage is unavailable.');
        }

        $password = $this->password($input['password'] ?? '', $input['password_confirmation'] ?? '');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to secure the administrator password.');
        }

        $this->adminCredentialRepository->saveAdminPasswordHash(
            $actor->username,
            $passwordHash,
            $this->actorId($actor),
        );
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
        $validEmail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false && strlen($username) <= 100;
        $validUsername = preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username) === 1;
        if (!$validEmail && !$validUsername) {
            throw new InvalidArgumentException('Login ID must be a valid email address or a 3-100 character username.');
        }

        return $username;
    }

    private function name(string $name, string $label): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) !== 1) {
            throw new InvalidArgumentException($label . ' is required and must be no more than 49 characters.');
        }
        return $name;
    }

    private function accountStatus(string $active): bool
    {
        if (!in_array($active, ['0', '1'], true)) {
            throw new InvalidArgumentException('Account status must be Active or Inactive.');
        }
        return $active === '1';
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
