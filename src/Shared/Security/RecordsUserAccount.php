<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class RecordsUserAccount
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $firstName,
        public readonly string $lastName,
        private readonly string $passwordHash,
        public readonly string $role,
        public readonly bool $active,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public function verifies(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function needsPasswordRehash(): bool
    {
        return PasswordHasher::needsRehash($this->passwordHash);
    }

    public function authenticationVersion(): string
    {
        return hash('sha256', implode('|', [
            $this->username,
            $this->firstName,
            $this->lastName,
            $this->passwordHash,
            $this->role,
            $this->active ? '1' : '0',
            $this->updatedAt,
        ]));
    }

    public function withUpdates(
        string $username,
        string $firstName,
        string $lastName,
        string $role,
        bool $active,
        ?string $passwordHash,
        string $updatedAt,
    ): self {
        return new self(
            $this->id,
            $username,
            $firstName,
            $lastName,
            $passwordHash ?? $this->passwordHash,
            $role,
            $active,
            $this->createdAt,
            $updatedAt,
        );
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
