<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        public readonly string $basePath = '',
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $basePath = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');

        if ($basePath !== '' && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
            $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
        }

        $normalizedPath = '/' . trim($requestPath, '/');
        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return new self($method, $normalizedPath, $_GET, $_POST, $basePath);
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}

