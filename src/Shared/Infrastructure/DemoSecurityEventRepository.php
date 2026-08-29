<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\SecurityEventRepository;

final class DemoSecurityEventRepository implements SecurityEventRepository
{
    private const SESSION_KEY = '_demo_security_events';

    public function record(
        string $eventName,
        string $outcome,
        string $requestId,
        string $clientId,
        string $method,
        string $path,
        string $occurredAt,
        array $context,
    ): void {
        $events = $_SESSION[self::SESSION_KEY] ?? [];
        $events = is_array($events) ? $events : [];
        $events[] = $this->row($eventName, $outcome, $requestId, $clientId, $method, $path, $occurredAt, $context);
        $_SESSION[self::SESSION_KEY] = array_slice($events, -500);
    }

    public function recentPickupsheet(int $limit): array
    {
        $events = $_SESSION[self::SESSION_KEY] ?? [];
        $events = array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_array($event)
                && str_starts_with((string) ($event['eventName'] ?? ''), 'pickupsheet.'),
        ));
        return array_slice(array_reverse($events), 0, max(1, min($limit, 100)));
    }

    /** @param array<string, bool|float|int|string|null> $context @return array<string, mixed> */
    private function row(
        string $eventName,
        string $outcome,
        string $requestId,
        string $clientId,
        string $method,
        string $path,
        string $occurredAt,
        array $context,
    ): array {
        return [
            'eventName' => $eventName,
            'outcome' => $outcome,
            'actorId' => (string) ($context['actor_id'] ?? ''),
            'targetId' => (string) ($context['target_id'] ?? ''),
            'resourceId' => (string) ($context['resource_id'] ?? ''),
            'role' => (string) ($context['role'] ?? ''),
            'identityProvider' => (string) ($context['identity_provider'] ?? ''),
            'requestId' => $requestId,
            'clientId' => $clientId,
            'method' => $method,
            'path' => $path,
            'occurredAt' => $occurredAt,
            'context' => $context,
        ];
    }
}
