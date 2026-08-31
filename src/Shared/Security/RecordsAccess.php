<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;
use Throwable;

final class RecordsAccess
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /** @var array<string, array{passwordHash: string, role: string, firstName: string, lastName: string}> */
    private array $users = [];
    private readonly ?RecordsAdminCredentialRepository $adminCredentialRepository;

    /**
     * @param list<array{username: string, passwordHash: string, role: string, firstName?: string, lastName?: string}> $users
     */
    public function __construct(
        array $users = [],
        private readonly ?RecordsUserRepository $repository = null,
        ?RecordsAdminCredentialRepository $adminCredentialRepository = null,
    ) {
        $this->adminCredentialRepository = $adminCredentialRepository
            ?? ($repository instanceof RecordsAdminCredentialRepository ? $repository : null);
        foreach ($users as $user) {
            $username = strtolower(trim($user['username'] ?? ''));
            $passwordHash = trim($user['passwordHash'] ?? '');
            $role = strtolower(trim($user['role'] ?? ''));
            $fallbackName = RecordsPrincipal::fallbackNameParts($username);
            $firstName = trim($user['firstName'] ?? '');
            $lastName = trim($user['lastName'] ?? '');
            $firstName = $firstName !== '' ? $firstName : $fallbackName['firstName'];
            $lastName = $lastName !== '' ? $lastName : $fallbackName['lastName'];

            if (!$this->validUsername($username)
                || !$this->validPasswordHash($passwordHash)
                || !$this->validName($firstName)
                || !$this->validName($lastName)
                || !RecordsPrincipal::isValidRole($role)
                || isset($this->users[$username])) {
                continue;
            }

            $this->users[$username] = [
                'passwordHash' => $passwordHash,
                'role' => $role,
                'firstName' => $firstName,
                'lastName' => $lastName,
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
            $parts = array_map('trim', explode('|', $entry));
            if (!in_array(count($parts), [3, 5], true)) {
                continue;
            }

            $users[] = [
                'username' => $parts[0],
                'role' => strtolower($parts[1]),
                'firstName' => count($parts) === 5 ? $parts[2] : '',
                'lastName' => count($parts) === 5 ? $parts[3] : '',
                'passwordHash' => count($parts) === 5 ? $parts[4] : $parts[2],
            ];
        }

        $legacyUsername = trim((string) (getenv('PICKUPSHEET_RECORDS_USER') ?: ''));
        $legacyPasswordHash = trim((string) (getenv('PICKUPSHEET_RECORDS_PASSWORD_HASH') ?: ''));
        if ($legacyUsername !== '' || $legacyPasswordHash !== '') {
            $users[] = [
                'username' => $legacyUsername,
                'role' => 'admin',
                'firstName' => trim((string) (getenv('PICKUPSHEET_RECORDS_FIRST_NAME') ?: '')),
                'lastName' => trim((string) (getenv('PICKUPSHEET_RECORDS_LAST_NAME') ?: '')),
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
        $username = strtolower(trim($username));
        $environmentUser = $this->users[$username] ?? null;
        if ($environmentUser !== null) {
            $passwordHash = $this->environmentPasswordHash($username, $environmentUser['passwordHash']);
            if ($passwordHash === null) {
                password_verify($password, self::DUMMY_PASSWORD_HASH);
                return null;
            }
            if (!password_verify($password, $passwordHash)) {
                return null;
            }
            return new RecordsPrincipal(
                $username,
                $environmentUser['role'],
                $this->environmentAuthenticationVersion($username, $environmentUser, $passwordHash),
                $environmentUser['firstName'],
                $environmentUser['lastName'],
                'local',
                '',
                'local-env:' . $username,
            );
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
        if ($managedAccount->needsPasswordRehash()) {
            try {
                $updated = $this->repository?->update(
                    $managedAccount->id,
                    $managedAccount->username,
                    $managedAccount->firstName,
                    $managedAccount->lastName,
                    $managedAccount->role,
                    $managedAccount->active,
                    PasswordHasher::hash($password),
                    substr(hash('sha256', $managedAccount->username), 0, 24),
                );
                if ($updated !== null) {
                    $managedAccount = $updated;
                }
            } catch (Throwable) {
                // A successful login remains available if opportunistic rehashing fails.
            }
        }

        return new RecordsPrincipal(
            $managedAccount->username,
            $managedAccount->role,
            $managedAccount->authenticationVersion(),
            $managedAccount->firstName,
            $managedAccount->lastName,
            'local',
            '',
            'local-user:' . $managedAccount->id,
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
            $managedAccount->firstName,
            $managedAccount->lastName,
            'local',
            '',
            'local-user:' . $managedAccount->id,
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
        $validEmail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false && strlen($username) <= 100;
        $validUsername = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $username) === 1;
        return $validEmail || $validUsername;
    }

    private function validPasswordHash(string $passwordHash): bool
    {
        return $passwordHash !== ''
            && password_get_info($passwordHash)['algoName'] !== 'unknown';
    }

    private function validName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) === 1;
    }

    /** @param array{passwordHash: string, role: string, firstName: string, lastName: string} $user */
    private function environmentPrincipal(string $username, array $user): ?RecordsPrincipal
    {
        $passwordHash = $this->environmentPasswordHash($username, $user['passwordHash']);
        if ($passwordHash === null) {
            return null;
        }
        return new RecordsPrincipal(
            $username,
            $user['role'],
            $this->environmentAuthenticationVersion($username, $user, $passwordHash),
            $user['firstName'],
            $user['lastName'],
            'local',
            '',
            'local-env:' . $username,
        );
    }

    /** @param array{passwordHash: string, role: string, firstName: string, lastName: string} $user */
    private function environmentAuthenticationVersion(string $username, array $user, string $passwordHash): string
    {
        return hash('sha256', implode('|', [
            $username,
            $user['role'],
            $user['firstName'],
            $user['lastName'],
            $passwordHash,
        ]));
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
