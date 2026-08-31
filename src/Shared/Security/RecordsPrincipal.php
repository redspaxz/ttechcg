<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class RecordsPrincipal
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'viewer' => ['create', 'list', 'paginate'],
        'operator' => ['crm_view', 'crm_update', 'create', 'list', 'paginate', 'print', 'export'],
        'admin' => ['dashboard', 'crm', 'crm_view', 'crm_update', 'backup', 'create', 'edit', 'mark_paid', 'delete', 'list', 'paginate', 'print', 'export', 'manage'],
    ];

    public function __construct(
        public readonly string $username,
        public readonly string $role,
        public readonly string $authenticationVersion = '',
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly string $identityProvider = 'local',
        public readonly string $displayName = '',
        public readonly string $localSecuritySubject = '',
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, self::ROLE_PERMISSIONS[$this->role] ?? [], true);
    }

    public static function isValidRole(string $role): bool
    {
        return array_key_exists($role, self::ROLE_PERMISSIONS);
    }

    public function fullName(): string
    {
        $displayName = trim($this->displayName);
        if ($displayName !== '') {
            return $displayName;
        }

        $name = trim($this->firstName . ' ' . $this->lastName);
        if ($name !== '') {
            return $name;
        }

        $fallback = self::fallbackNameParts($this->username);
        return $fallback['firstName'] . ' ' . $fallback['lastName'];
    }

    public function securitySubject(): string
    {
        if ($this->localSecuritySubject !== '') {
            return $this->localSecuritySubject;
        }
        return strtolower($this->identityProvider . ':' . $this->username);
    }

    /** @return array{firstName: string, lastName: string} */
    public static function fallbackNameParts(string $username): array
    {
        $words = preg_split('/[._@-]+/', trim($username), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = substr(ucfirst(strtolower((string) ($words[0] ?? 'Records'))), 0, 49);
        $lastName = substr(ucfirst(strtolower(implode(' ', array_slice($words, 1)))) ?: 'User', 0, 49);
        return ['firstName' => $firstName, 'lastName' => $lastName];
    }
}
