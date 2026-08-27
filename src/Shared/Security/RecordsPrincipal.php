<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class RecordsPrincipal
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'viewer' => ['create', 'list', 'paginate'],
        'operator' => ['create', 'edit', 'list', 'paginate', 'print', 'export'],
        'admin' => ['dashboard', 'create', 'list', 'paginate', 'print', 'export', 'manage'],
    ];

    public function __construct(
        public readonly string $username,
        public readonly string $role,
        public readonly string $authenticationVersion = '',
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
}
