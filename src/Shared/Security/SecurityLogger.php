<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;

final class SecurityLogger
{
    public function __construct(private readonly bool $enabled = true)
    {
    }

    /** @param array<string, bool|float|int|string|null> $context */
    public function event(string $event, Request $request, string $outcome, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $record = array_merge([
            'type' => 'security_event',
            'event' => preg_replace('/[^a-z0-9_.-]/i', '_', $event) ?: 'unknown',
            'outcome' => preg_replace('/[^a-z0-9_.-]/i', '_', $outcome) ?: 'unknown',
            'request_id' => $request->requestId(),
            'client_id' => $request->clientIdentifier(),
            'method' => $request->method,
            'path' => $request->path,
            'occurred_at' => gmdate(DATE_ATOM),
        ], $context);

        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log(is_string($encoded) ? $encoded : '{"type":"security_event","event":"encoding_failed"}');
    }
}
