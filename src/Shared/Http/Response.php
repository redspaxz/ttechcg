<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }
}

