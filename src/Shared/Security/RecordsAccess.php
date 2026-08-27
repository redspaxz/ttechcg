<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class RecordsAccess
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /** @var array<string, array{passwordHash: string, role: string}> */
    private array $users = [];

    /**
     * @param list<array{username: string, passwordHash: string, role: string}> $users
     */
    public function __construct(array $users = [])
    {
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

    public static function fromEnvironment(): self
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

        return new self($users);
    }

    public function authenticate(Request $request): ?RecordsPrincipal
    {
        $credentials = $request->basicCredentials();
        if ($credentials === null) {
            return null;
        }

        [$username, $password] = $credentials;
        $user = $this->users[$username] ?? null;
        $passwordMatches = password_verify($password, $user['passwordHash'] ?? self::DUMMY_PASSWORD_HASH);
        if ($user === null || !$passwordMatches) {
            return null;
        }

        return new RecordsPrincipal($username, $user['role']);
    }

    public function isConfigured(): bool
    {
        return $this->users !== [];
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
}
