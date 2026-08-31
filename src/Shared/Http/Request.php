<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Security\CloudflareRequestTrust;

final class Request
{
    private ?string $generatedRequestId = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        public readonly string $basePath = '',
        private readonly array $server = [],
        private readonly array $files = [],
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

        return new self($method, $normalizedPath, $_GET, $_POST, $basePath, $_SERVER, $_FILES);
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

    /** @return null|array{name: string, type: string, tmpName: string, error: int, size: int} */
    public function uploadedFile(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }

        return [
            'name' => is_string($file['name'] ?? null) ? basename($file['name']) : '',
            'type' => is_string($file['type'] ?? null) ? substr($file['type'], 0, 100) : '',
            'tmpName' => is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
            'error' => is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            'size' => is_int($file['size'] ?? null) ? max(0, $file['size']) : 0,
        ];
    }

    public function header(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9-]{1,100}$/', $name) !== 1) {
            return '';
        }
        return $this->serverString('HTTP_' . strtoupper(str_replace('-', '_', $name)));
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
        $cloudflareAddress = $this->validatedIp($this->trustedCloudflareHeader('CF-Connecting-IP'));
        $remoteAddress = $this->validatedIp($this->serverString('REMOTE_ADDR'));
        $address = $cloudflareAddress ?? $remoteAddress ?? 'unknown';

        return hash('sha256', $address);
    }

    public function requestId(): string
    {
        $cloudflareRay = $this->trustedCloudflareHeader('CF-Ray');
        if ($cloudflareRay !== '' && preg_match('/^[A-Za-z0-9-]{1,100}$/', $cloudflareRay)) {
            return $cloudflareRay;
        }

        return $this->generatedRequestId ??= bin2hex(random_bytes(12));
    }

    public function trustedCloudflareHeader(string $name): string
    {
        $remoteAddress = $this->validatedIp($this->serverString('REMOTE_ADDR'));
        if ($remoteAddress === null || !CloudflareRequestTrust::contains($remoteAddress)) {
            return '';
        }
        return $this->header($name);
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

