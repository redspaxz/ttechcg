<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class RecordsPrincipal
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'viewer' => ['list', 'paginate'],
        'operator' => ['list', 'paginate', 'print', 'export'],
        'admin' => ['list', 'paginate', 'print', 'export', 'manage'],
    ];

    public function __construct(
        public readonly string $username,
        public readonly string $role,
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
