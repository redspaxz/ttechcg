<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain;

final class Inquiry
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $company,
        public readonly string $service,
        public readonly string $message,
        public readonly string $createdAt,
    ) {
    }
}

