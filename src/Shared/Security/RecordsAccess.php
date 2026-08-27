<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;
use Throwable;

final class RecordsAccess
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /** @var array<string, array{passwordHash: string, role: string}> */
    private array $users = [];
    private readonly ?RecordsAdminCredentialRepository $adminCredentialRepository;

    /**
     * @param list<array{username: string, passwordHash: string, role: string}> $users
     */
    public function __construct(
        array $users = [],
        private readonly ?RecordsUserRepository $repository = null,
        ?RecordsAdminCredentialRepository $adminCredentialRepository = null,
    ) {
        $this->adminCredentialRepository = $adminCredentialRepository
            ?? ($repository instanceof RecordsAdminCredentialRepository ? $repository : null);
        foreach ($users as $user) {
            $username = trim($user['username'] ?? '');
            $passwordHash = trim($user['passwordHash'] ?? '');
            $role = strtolower(trim($user['role'] ?? ''));

            if (!$this->validUsername($username)
                || !$this->validPasswordHash($passwordHash)
                || !RecordsPrincipal::isValidRole($role)
                || isset($this->users[$username])) {
                continue;
            }

            $this->users[$username] = [
                'passwordHash' => $passwordHash,
                'role' => $role,
            ];
        }
    }

    public static function fromEnvironment(
        ?RecordsUserRepository $repository = null,
        ?RecordsAdminCredentialRepository $adminCredentialRepository = null,
    ): self
    {
        $users = [];
        $entries = trim((string) (getenv('PICKUPSHEET_RBAC_USERS') ?: ''));

        foreach (array_filter(array_map('trim', explode(';', $entries))) as $entry) {
            $parts = array_map('trim', explode('|', $entry, 3));
            if (count($parts) !== 3) {
                continue;
            }

            $users[] = [
                'username' => $parts[0],
                'role' => strtolower($parts[1]),
                'passwordHash' => $parts[2],
            ];
        }

        $legacyUsername = trim((string) (getenv('PICKUPSHEET_RECORDS_USER') ?: ''));
        $legacyPasswordHash = trim((string) (getenv('PICKUPSHEET_RECORDS_PASSWORD_HASH') ?: ''));
        if ($legacyUsername !== '' || $legacyPasswordHash !== '') {
            $users[] = [
                'username' => $legacyUsername,
                'role' => 'admin',
                'passwordHash' => $legacyPasswordHash,
            ];
        }

        return new self($users, $repository, $adminCredentialRepository);
    }

    public function authenticate(Request $request): ?RecordsPrincipal
    {
        $credentials = $request->basicCredentials();
        if ($credentials === null) {
            return null;
        }

        return $this->authenticateCredentials($credentials[0], $credentials[1]);
    }

    public function authenticateCredentials(string $username, string $password): ?RecordsPrincipal
    {
        $username = trim($username);
        $environmentUser = $this->users[$username] ?? null;
        if ($environmentUser !== null) {
            $passwordHash = $this->environmentPasswordHash($username, $environmentUser['passwordHash']);
            if ($passwordHash === null) {
                password_verify($password, self::DUMMY_PASSWORD_HASH);
                return null;
            }
            return password_verify($password, $passwordHash)
                ? new RecordsPrincipal(
                    $username,
                    $environmentUser['role'],
                    hash('sha256', $username . '|' . $environmentUser['role'] . '|' . $passwordHash),
                )
                : null;
        }

        $managedAccount = null;
        try {
            $managedAccount = $this->repository?->findActiveByUsername($username);
        } catch (Throwable) {
            // Authentication fails closed; the management page reports storage availability.
        }

        if ($managedAccount === null) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return null;
        }

        if (!$managedAccount->verifies($password)
            || !in_array($managedAccount->role, ['viewer', 'operator'], true)) {
            return null;
        }

        return new RecordsPrincipal(
            $managedAccount->username,
            $managedAccount->role,
            $managedAccount->authenticationVersion(),
        );
    }

    public function resolvePrincipal(string $username): ?RecordsPrincipal
    {
        $environmentUser = $this->users[$username] ?? null;
        if ($environmentUser !== null) {
            return $this->environmentPrincipal($username, $environmentUser);
        }

        try {
            $managedAccount = $this->repository?->findActiveByUsername($username);
        } catch (Throwable) {
            return null;
        }

        if ($managedAccount === null || !in_array($managedAccount->role, ['viewer', 'operator'], true)) {
            return null;
        }

        return new RecordsPrincipal(
            $managedAccount->username,
            $managedAccount->role,
            $managedAccount->authenticationVersion(),
        );
    }

    public function isConfigured(): bool
    {
        return $this->users !== [] || $this->repository !== null;
    }

    /** @return list<string> */
    public function environmentUsernames(): array
    {
        return array_keys($this->users);
    }

    private function validUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9._@-]{1,100}$/', $username) === 1;
    }

    private function validPasswordHash(string $passwordHash): bool
    {
        return $passwordHash !== ''
            && password_get_info($passwordHash)['algoName'] !== 'unknown';
    }

    /** @param array{passwordHash: string, role: string} $user */
    private function environmentPrincipal(string $username, array $user): ?RecordsPrincipal
    {
        $passwordHash = $this->environmentPasswordHash($username, $user['passwordHash']);
        if ($passwordHash === null) {
            return null;
        }
        return new RecordsPrincipal(
            $username,
            $user['role'],
            hash('sha256', $username . '|' . $user['role'] . '|' . $passwordHash),
        );
    }

    private function environmentPasswordHash(string $username, string $fallbackHash): ?string
    {
        if ($this->adminCredentialRepository === null) {
            return $fallbackHash;
        }

        try {
            return $this->adminCredentialRepository->adminPasswordHash($username) ?? $fallbackHash;
        } catch (Throwable) {
            return null;
        }
    }
}
