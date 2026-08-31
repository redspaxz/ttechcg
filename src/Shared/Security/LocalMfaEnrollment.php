<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class LocalMfaEnrollment
{
    /** @param list<string> $recoveryCodeHashes */
    public function __construct(
        public readonly string $subjectId,
        public readonly string $username,
        public readonly string $secretEnvelope,
        public readonly array $recoveryCodeHashes,
        public readonly ?int $lastUsedStep,
        public readonly string $enabledAt,
    ) {
    }
}
