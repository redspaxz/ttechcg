<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface OidcHttpClient
{
    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $fields, array $headers = []): array;

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array;
}
