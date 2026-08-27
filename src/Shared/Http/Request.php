<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        public readonly string $basePath = '',
        private readonly array $server = [],
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

        return new self($method, $normalizedPath, $_GET, $_POST, $basePath, $_SERVER);
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function rawInput(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @return array<mixed> */
    public function arrayInput(string $key): array
    {
        $value = $this->body[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @return null|array{0: string, 1: string} */
    public function basicCredentials(): ?array
    {
        $user = $this->serverString('PHP_AUTH_USER');
        $password = $this->serverString('PHP_AUTH_PW');
        if ($user !== '' || $password !== '') {
            return [$user, $password];
        }

        $authorization = $this->serverString('HTTP_AUTHORIZATION');
        if ($authorization === '') {
            $authorization = $this->serverString('REDIRECT_HTTP_AUTHORIZATION');
        }
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $authorization, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return null;
        }

        [$user, $password] = explode(':', $decoded, 2);
        return [$user, $password];
    }

    public function clientIdentifier(): string
    {
        $cloudflareAddress = $this->validatedIp($this->serverString('HTTP_CF_CONNECTING_IP'));
        $remoteAddress = $this->validatedIp($this->serverString('REMOTE_ADDR'));
        $address = $cloudflareAddress ?? $remoteAddress ?? 'unknown';

        return hash('sha256', $address);
    }

    public function requestId(): string
    {
        $cloudflareRay = $this->serverString('HTTP_CF_RAY');
        if ($cloudflareRay !== '' && preg_match('/^[A-Za-z0-9-]{1,100}$/', $cloudflareRay)) {
            return $cloudflareRay;
        }

        return substr(hash('sha256', $this->method . '|' . $this->path . '|' . $this->clientIdentifier()), 0, 24);
    }

    private function serverString(string $key): string
    {
        $value = $this->server[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function validatedIp(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}

