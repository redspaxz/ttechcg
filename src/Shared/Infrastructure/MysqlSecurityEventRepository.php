<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\SecurityEventRepository;
use PDO;
use PDOException;

final class MysqlSecurityEventRepository implements SecurityEventRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

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
        if ($this->optionalIdentifier($context['actor_id'] ?? null) !== null) {
            $this->ensureSchema();
        }

        try {
            $statement = $this->connection->prepare(
                'INSERT INTO pickup_security_events
                    (event_name, outcome, actor_id, target_id, resource_id, role, identity_provider,
                     request_id, client_id, request_method, request_path, context_json, occurred_at)
                 VALUES
                    (:event_name, :outcome, :actor_id, :target_id, :resource_id, :role, :identity_provider,
                     :request_id, :client_id, :request_method, :request_path, :context_json, :occurred_at)',
            );
            $statement->execute([
                'event_name' => $eventName,
                'outcome' => $outcome,
                'actor_id' => $this->optionalIdentifier($context['actor_id'] ?? null),
                'target_id' => $this->optionalIdentifier($context['target_id'] ?? null),
                'resource_id' => $this->optionalIdentifier($context['resource_id'] ?? null),
                'role' => $this->optionalText($context['role'] ?? null, 20),
                'identity_provider' => $this->optionalText($context['identity_provider'] ?? null, 32),
                'request_id' => substr($requestId, 0, 100),
                'client_id' => substr($clientId, 0, 64),
                'request_method' => substr($method, 0, 10),
                'request_path' => substr($path, 0, 190),
                'context_json' => $this->contextJson($context),
                'occurred_at' => gmdate('Y-m-d H:i:s', strtotime($occurredAt) ?: time()),
            ]);
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) {
                return;
            }
            throw $exception;
        }
    }

    public function recentPickupsheet(int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            "SELECT event_name, outcome, actor_id, target_id, resource_id, role, identity_provider,
                    request_id, client_id, request_method, request_path, context_json, occurred_at
             FROM pickup_security_events
             WHERE event_name LIKE 'pickupsheet.%'
             ORDER BY occurred_at DESC, id DESC
             LIMIT :limit",
        );
        $statement->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): array => [
            'eventName' => (string) $row['event_name'],
            'outcome' => (string) $row['outcome'],
            'actorId' => (string) ($row['actor_id'] ?? ''),
            'targetId' => (string) ($row['target_id'] ?? ''),
            'resourceId' => (string) ($row['resource_id'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'identityProvider' => (string) ($row['identity_provider'] ?? ''),
            'requestId' => (string) $row['request_id'],
            'clientId' => (string) $row['client_id'],
            'method' => (string) $row['request_method'],
            'path' => (string) $row['request_path'],
            'occurredAt' => (string) $row['occurred_at'],
            'context' => $this->decodedContext($row['context_json'] ?? null),
        ], $statement->fetchAll());
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_security_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_name VARCHAR(100) NOT NULL,
                outcome VARCHAR(30) NOT NULL,
                actor_id CHAR(24) NULL,
                target_id CHAR(24) NULL,
                resource_id CHAR(24) NULL,
                role VARCHAR(20) NULL,
                identity_provider VARCHAR(32) NULL,
                request_id VARCHAR(100) NOT NULL,
                client_id CHAR(64) NOT NULL,
                request_method VARCHAR(10) NOT NULL,
                request_path VARCHAR(190) NOT NULL,
                context_json LONGTEXT NULL,
                occurred_at DATETIME NOT NULL,
                INDEX pickup_security_events_time_idx (occurred_at),
                INDEX pickup_security_events_actor_time_idx (actor_id, occurred_at),
                INDEX pickup_security_events_event_outcome_idx (event_name, outcome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->schemaReady = true;
    }

    private function optionalIdentifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{24}$/', $value) === 1 ? $value : null;
    }

    private function optionalText(mixed $value, int $maximumLength): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, $maximumLength) : null;
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private function contextJson(array $context): ?string
    {
        $details = array_diff_key($context, array_flip([
            'actor_id', 'target_id', 'resource_id', 'role', 'identity_provider',
        ]));
        if ($details === []) {
            return null;
        }
        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : null;
    }

    /** @return array<string, bool|float|int|string|null> */
    private function decodedContext(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_filter(
            $decoded,
            static fn (mixed $item): bool => is_bool($item)
                || is_float($item)
                || is_int($item)
                || is_string($item)
                || $item === null,
        );
    }
}
