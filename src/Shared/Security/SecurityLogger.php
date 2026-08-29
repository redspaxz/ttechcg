<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\Request;
use Throwable;

final class SecurityLogger
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly ?SecurityEventRepository $repository = null,
        private readonly bool $emitToErrorLog = true,
    ) {
    }

    /** @param array<string, bool|float|int|string|null> $context */
    public function event(string $event, Request $request, string $outcome, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $eventName = preg_replace('/[^a-z0-9_.-]/i', '_', $event) ?: 'unknown';
        $eventOutcome = preg_replace('/[^a-z0-9_.-]/i', '_', $outcome) ?: 'unknown';
        $occurredAt = gmdate(DATE_ATOM);
        $record = array_merge([
            'type' => 'security_event',
            'event' => $eventName,
            'outcome' => $eventOutcome,
            'request_id' => $request->requestId(),
            'client_id' => $request->clientIdentifier(),
            'method' => $request->method,
            'path' => $request->path,
            'occurred_at' => $occurredAt,
        ], $context);

        if ($this->emitToErrorLog) {
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log(is_string($encoded) ? $encoded : '{"type":"security_event","event":"encoding_failed"}');
        }

        if ($this->repository !== null) {
            try {
                $this->repository->record(
                    $eventName,
                    $eventOutcome,
                    $request->requestId(),
                    $request->clientIdentifier(),
                    $request->method,
                    $request->path,
                    $occurredAt,
                    $context,
                );
            } catch (Throwable $exception) {
                if ($this->emitToErrorLog) {
                    error_log('Pickupsheet audit event could not be persisted: ' . $exception->getMessage());
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function recentPickupsheet(int $limit = 50): array
    {
        return $this->repository?->recentPickupsheet(max(1, min($limit, 100))) ?? [];
    }
}
