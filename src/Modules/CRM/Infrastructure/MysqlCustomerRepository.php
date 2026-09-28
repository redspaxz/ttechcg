<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

use App\Modules\CRM\Domain\CustomerMergePolicy;
use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MysqlCustomerRepository implements CustomerRepository
{
    private bool $schemaReady = false;
    private bool $synchronized = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function synchronizeFromShipments(): void
    {
        if ($this->synchronized) {
            return;
        }
        $this->ensureSchema();
        $this->resolveShipmentAliases();
        // Group by the column collation (case- and accent-insensitive) so spelling variants that the
        // unique display-name index treats as equal become one profile. A name-derived key that is
        // still held by a renamed profile falls back to a random key instead of dropping the sender.
        $this->connection->exec(
            "INSERT INTO pickup_customers
                (customer_key, display_name, country_code, status, source, assigned_role, created_at, updated_at)
             SELECT CASE WHEN key_owner.id IS NULL THEN candidates.name_key
                         ELSE SHA2(CONCAT(candidates.name_key, ':', UUID()), 256) END,
                    candidates.display_name, 'CM', 'active', 'shipment', 'admin', UTC_TIMESTAMP(), UTC_TIMESTAMP()
             FROM (
                 SELECT SHA2(LOWER(MIN(TRIM(ps.consignor))), 256) AS name_key, MIN(TRIM(ps.consignor)) AS display_name
                 FROM pickup_shipments ps
                 INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                 LEFT JOIN pickup_customers existing_customer
                   ON LOWER(TRIM(existing_customer.display_name)) = LOWER(TRIM(ps.consignor))
                 WHERE p.deleted_at IS NULL
                   AND TRIM(ps.consignor) <> ''
                   AND existing_customer.id IS NULL
                 GROUP BY LOWER(TRIM(ps.consignor))
             ) candidates
             LEFT JOIN pickup_customers key_owner ON key_owner.customer_key = candidates.name_key
             ON DUPLICATE KEY UPDATE pickup_customers.id = pickup_customers.id",
        );
        $this->synchronized = true;
    }

    /** Points shipments still typed with a merged-away name at the profile that absorbed it. */
    private function resolveShipmentAliases(): void
    {
        if ($this->connection->query('SELECT 1 FROM pickup_customer_aliases LIMIT 1')->fetchColumn() === false) {
            return;
        }
        $this->connection->exec(
            'UPDATE pickup_shipments ps
             INNER JOIN pickup_customer_aliases alias_map ON alias_map.alias_name = TRIM(ps.consignor)
             INNER JOIN pickup_customers c ON c.customer_key = alias_map.customer_key
             SET ps.consignor = c.display_name',
        );
    }

    public function paginated(string $search, string $status, int $limit, int $offset): array
    {
        $this->ensureSchema();
        [$where, $parameters] = $this->filters($search, $status);
        $countStatement = $this->connection->prepare('SELECT COUNT(*) FROM pickup_customers c' . $where);
        $countStatement->execute($parameters);
        $totalRecords = (int) $countStatement->fetchColumn();

        $statement = $this->connection->prepare(
            $this->customerSelect()
            . $where
            . " ORDER BY
                    CASE WHEN c.next_follow_up_on IS NOT NULL AND c.next_follow_up_on <= UTC_DATE() AND c.status <> 'inactive' THEN 0 ELSE 1 END,
                    CASE c.status WHEN 'attention' THEN 0 WHEN 'lead' THEN 1 WHEN 'active' THEN 2 ELSE 3 END,
                    COALESCE(metrics.last_shipment_on, '0000-00-00') DESC,
                    c.display_name ASC
                LIMIT :limit OFFSET :offset",
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(fn (array $row): CustomerProfile => $this->profile($row), $statement->fetchAll()),
            'totalRecords' => $totalRecords,
        ];
    }

    public function summary(): array
    {
        $this->ensureSchema();
        $statement = $this->connection->query(
            "SELECT COUNT(*) AS customer_count,
                    COALESCE(SUM(status = 'active'), 0) AS active_count,
                    COALESCE(SUM(status = 'attention'), 0) AS attention_count,
                    COALESCE(SUM(next_follow_up_on IS NOT NULL AND next_follow_up_on <= UTC_DATE() AND status <> 'inactive'), 0) AS follow_ups_due
             FROM pickup_customers",
        );
        $row = $statement->fetch();

        return [
            'customerCount' => (int) ($row['customer_count'] ?? 0),
            'activeCount' => (int) ($row['active_count'] ?? 0),
            'attentionCount' => (int) ($row['attention_count'] ?? 0),
            'followUpsDue' => (int) ($row['follow_ups_due'] ?? 0),
        ];
    }

    public function topByRewardPoints(int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            $this->customerSelect()
            . ' ORDER BY
                    GREATEST(0, COALESCE(metrics.cargo_reward_points, 0) + COALESCE(rewards.adjustment_points, 0)) DESC,
                    COALESCE(metrics.cargo_reward_points, 0) + COALESCE(rewards.earned_adjustment_points, 0) DESC,
                    LOWER(c.display_name) ASC,
                    c.display_name ASC
                LIMIT :limit',
        );
        $statement->bindValue(':limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): CustomerProfile => $this->profile($row), $statement->fetchAll());
    }

    public function suggestions(string $query, int $limit): array
    {
        $this->ensureSchema();
        $query = trim($query);
        $statement = $this->connection->prepare(
            'SELECT display_name
             FROM pickup_customers
             WHERE LEFT(LOWER(TRIM(display_name)), CHAR_LENGTH(LOWER(:query_length))) = LOWER(:query_prefix)
             ORDER BY CASE WHEN LOWER(TRIM(display_name)) = LOWER(:query_exact) THEN 0 ELSE 1 END,
                      CHAR_LENGTH(TRIM(display_name)), LOWER(TRIM(display_name)), display_name
             LIMIT :limit',
        );
        $statement->bindValue(':query_length', $query);
        $statement->bindValue(':query_prefix', $query);
        $statement->bindValue(':query_exact', $query);
        $statement->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ), static fn (string $name): bool => $name !== ''));
    }

    public function duplicateReviewNames(int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare('SELECT customer_key, display_name FROM pickup_customers ORDER BY id LIMIT :limit');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'customerKey' => (string) $row['customer_key'],
            'displayName' => (string) $row['display_name'],
        ], $statement->fetchAll());
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare($this->customerSelect() . ' WHERE c.customer_key = :customer_key LIMIT 1');
        $statement->execute(['customer_key' => $customerKey]);
        $row = $statement->fetch();
        return is_array($row) ? $this->profile($row) : null;
    }

    public function findByName(string $name): ?array
    {
        $this->ensureSchema();
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $statement = $this->connection->prepare(
            'SELECT customer_key FROM pickup_customers WHERE LOWER(TRIM(display_name)) = LOWER(:display_name) LIMIT 1',
        );
        $statement->execute(['display_name' => $name]);
        $customerKey = $statement->fetchColumn();
        $alias = null;
        if (!is_string($customerKey)) {
            $aliasStatement = $this->connection->prepare(
                'SELECT customer_key, alias_name FROM pickup_customer_aliases WHERE alias_name = :alias_name LIMIT 1',
            );
            $aliasStatement->execute(['alias_name' => $name]);
            $aliasRow = $aliasStatement->fetch();
            if (!is_array($aliasRow)) {
                return null;
            }
            $customerKey = (string) $aliasRow['customer_key'];
            $alias = (string) $aliasRow['alias_name'];
        }
        $customer = $this->find($customerKey);
        return $customer === null ? null : ['customer' => $customer, 'alias' => $alias];
    }

    public function recentShipments(string $customerKey, int $limit, int $offset = 0): array
    {
        $statement = $this->connection->prepare(
            "SELECT p.reference_number, p.collection_date, ps.awb_number, ps.destination,
                    ps.amount_xaf, COALESCE(p.status, 'open') AS sheet_status
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             INNER JOIN pickup_customers c ON c.customer_key = :customer_key
             WHERE p.deleted_at IS NULL
               AND LOWER(TRIM(ps.consignor)) = LOWER(TRIM(c.display_name))
             ORDER BY p.collection_date DESC, p.id DESC, ps.line_number DESC
             LIMIT :limit OFFSET :offset",
        );
        $statement->bindValue(':customer_key', $customerKey);
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'referenceNumber' => (string) $row['reference_number'],
            'collectionDate' => (string) $row['collection_date'],
            'awbNumber' => (string) $row['awb_number'],
            'destination' => (string) $row['destination'],
            'amountXaf' => (int) $row['amount_xaf'],
            'status' => (string) $row['sheet_status'],
        ], $statement->fetchAll());
    }

    public function shipmentCount(string $customerKey): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             INNER JOIN pickup_customers c ON c.customer_key = :customer_key
             WHERE p.deleted_at IS NULL
               AND LOWER(TRIM(ps.consignor)) = LOWER(TRIM(c.display_name))',
        );
        $statement->execute(['customer_key' => $customerKey]);
        return (int) $statement->fetchColumn();
    }

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $existingStatement = $this->connection->prepare(
                'SELECT id, display_name FROM pickup_customers WHERE customer_key = :customer_key LIMIT 1 FOR UPDATE',
            );
            $existingStatement->execute(['customer_key' => $customer->customerKey]);
            $existingRow = $existingStatement->fetch();
            if ($customer->id === null && is_array($existingRow)) {
                throw new InvalidArgumentException('A customer profile already uses this organization name.');
            }
            $previousDisplayName = is_array($existingRow) ? (string) $existingRow['display_name'] : false;

            $collisionStatement = $this->connection->prepare(
                'SELECT customer_key
                 FROM pickup_customers
                 WHERE LOWER(TRIM(display_name)) = LOWER(TRIM(:display_name))
                   AND customer_key <> :customer_key
                 LIMIT 1 FOR UPDATE',
            );
            $collisionStatement->execute([
                'display_name' => $customer->displayName,
                'customer_key' => $customer->customerKey,
            ]);
            if ($collisionStatement->fetchColumn() !== false) {
                throw new InvalidArgumentException('A customer profile already uses this organization name.');
            }

            $aliasStatement = $this->connection->prepare(
                'SELECT customer_key FROM pickup_customer_aliases WHERE alias_name = TRIM(:display_name) LIMIT 1 FOR UPDATE',
            );
            $aliasStatement->execute(['display_name' => $customer->displayName]);
            $aliasOwner = $aliasStatement->fetchColumn();
            if (is_string($aliasOwner) && $aliasOwner !== $customer->customerKey) {
                throw new InvalidArgumentException('This organization name was merged into another customer profile.');
            }
            if (is_string($aliasOwner)) {
                // Renaming a profile back to one of its own merged-away names retires that alias.
                $this->connection->prepare(
                    'DELETE FROM pickup_customer_aliases WHERE alias_name = TRIM(:display_name) AND customer_key = :customer_key',
                )->execute(['display_name' => $customer->displayName, 'customer_key' => $customer->customerKey]);
            }

            if (is_string($previousDisplayName) && trim($previousDisplayName) !== trim($customer->displayName)) {
                $renameStatement = $this->connection->prepare(
                    'UPDATE pickup_shipments
                     SET consignor = :display_name
                     WHERE LOWER(TRIM(consignor)) = LOWER(TRIM(:previous_display_name))',
                );
                $renameStatement->execute([
                    'display_name' => $customer->displayName,
                    'previous_display_name' => $previousDisplayName,
                ]);
            }

            $parameters = [
                'customer_key' => $customer->customerKey,
                'display_name' => $customer->displayName,
                'contact_name' => $this->nullable($customer->contactName),
                'email' => $this->nullable($customer->email),
                'phone' => $this->nullable($customer->phone),
                'address' => $this->nullable($customer->address),
                'city' => $this->nullable($customer->city),
                'country_code' => $this->nullable($customer->countryCode),
                'status' => $customer->status,
                'notes' => $this->nullable($customer->notes),
                'next_follow_up_on' => $customer->nextFollowUpOn,
                'source' => $customer->source,
                'assigned_role' => 'admin',
                'updated_by' => $actorId,
            ];
            if ($customer->id === null) {
                $statement = $this->connection->prepare(
                    'INSERT INTO pickup_customers
                        (customer_key, display_name, contact_name, email, phone, address, city, country_code,
                         status, notes, next_follow_up_on, source, assigned_role, created_by, updated_by, created_at, updated_at)
                     VALUES
                        (:customer_key, :display_name, :contact_name, :email, :phone, :address, :city, :country_code,
                         :status, :notes, :next_follow_up_on, :source, :assigned_role, :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                );
                $parameters['created_by'] = $actorId;
            } else {
                $statement = $this->connection->prepare(
                    'UPDATE pickup_customers
                     SET display_name = :display_name, contact_name = :contact_name, email = :email,
                         phone = :phone, address = :address, city = :city, country_code = :country_code,
                         status = :status, notes = :notes, next_follow_up_on = :next_follow_up_on,
                         source = :source, assigned_role = :assigned_role, updated_by = :updated_by,
                         updated_at = UTC_TIMESTAMP()
                     WHERE customer_key = :customer_key',
                );
            }
            $statement->execute($parameters);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException('A customer profile already uses this organization name.', 0, $exception);
            }
            throw $exception;
        }

        return $this->find($customer->customerKey) ?? $customer;
    }

    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $profileStatement = $this->connection->prepare(
                'SELECT customer_key, display_name, contact_name, email, phone, address, city, country_code,
                        status, notes, next_follow_up_on, source, assigned_role, created_by, updated_by, created_at, updated_at
                 FROM pickup_customers
                 WHERE customer_key IN (:target_key, :source_key)
                 ORDER BY customer_key
                 FOR UPDATE',
            );
            $profileStatement->execute(['target_key' => $targetCustomerKey, 'source_key' => $sourceCustomerKey]);
            $profiles = [];
            foreach ($profileStatement->fetchAll() as $profile) {
                $profiles[(string) $profile['customer_key']] = $profile;
            }
            $target = $profiles[$targetCustomerKey] ?? null;
            $source = $profiles[$sourceCustomerKey] ?? null;
            if (!is_array($target) || !is_array($source)) {
                throw new InvalidArgumentException('One of the customer profiles no longer exists.');
            }

            $shipmentSnapshotStatement = $this->connection->prepare(
                'SELECT pickup_sheet_id, line_number, consignor
                 FROM pickup_shipments
                 WHERE LOWER(TRIM(consignor)) = LOWER(TRIM(:source_name))
                 FOR UPDATE',
            );
            $shipmentSnapshotStatement->execute(['source_name' => (string) $source['display_name']]);
            $shipments = array_map(static fn (array $row): array => [
                (int) $row['pickup_sheet_id'],
                (int) $row['line_number'],
                (string) $row['consignor'],
            ], $shipmentSnapshotStatement->fetchAll());

            $shipmentStatement = $this->connection->prepare(
                'UPDATE pickup_shipments
                 SET consignor = :target_name
                 WHERE LOWER(TRIM(consignor)) = LOWER(TRIM(:source_name))',
            );
            $shipmentStatement->execute([
                'target_name' => (string) $target['display_name'],
                'source_name' => (string) $source['display_name'],
            ]);

            $rewardIds = $this->idsWhere('pickup_customer_reward_adjustments', $sourceCustomerKey);
            $this->connection->prepare(
                'UPDATE pickup_customer_reward_adjustments SET customer_key = :target_key WHERE customer_key = :source_key',
            )->execute(['target_key' => $targetCustomerKey, 'source_key' => $sourceCustomerKey]);

            $aliasIds = $this->idsWhere('pickup_customer_aliases', $sourceCustomerKey);
            $this->connection->prepare(
                'UPDATE pickup_customer_aliases SET customer_key = :target_key WHERE customer_key = :source_key',
            )->execute(['target_key' => $targetCustomerKey, 'source_key' => $sourceCustomerKey]);

            $targetBefore = $this->mergeFields($target);
            $targetAfter = CustomerMergePolicy::merge(
                $targetBefore,
                $this->mergeFields($source) + ['displayName' => (string) $source['display_name']],
            );
            $this->writeMergeFields($targetCustomerKey, $targetAfter, $actorId);

            $this->connection->prepare('DELETE FROM pickup_customers WHERE customer_key = :source_key')
                ->execute(['source_key' => $sourceCustomerKey]);
            $this->connection->prepare(
                'INSERT INTO pickup_customer_aliases (customer_key, alias_name, created_by, created_at)
                 VALUES (:customer_key, :alias_name, :created_by, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE customer_key = VALUES(customer_key)',
            )->execute([
                'customer_key' => $targetCustomerKey,
                'alias_name' => trim((string) $source['display_name']),
                'created_by' => $actorId,
            ]);

            $this->connection->prepare(
                'INSERT INTO pickup_customer_merges
                    (target_customer_key, source_customer_key, target_display_name, source_display_name, snapshot, merged_by, merged_at)
                 VALUES (:target_key, :source_key, :target_name, :source_name, :snapshot, :merged_by, UTC_TIMESTAMP())',
            )->execute([
                'target_key' => $targetCustomerKey,
                'source_key' => $sourceCustomerKey,
                'target_name' => (string) $target['display_name'],
                'source_name' => (string) $source['display_name'],
                'snapshot' => json_encode([
                    'source' => $source,
                    'targetBefore' => $targetBefore,
                    'targetAfter' => $targetAfter,
                    'shipments' => $shipments,
                    'rewardIds' => $rewardIds,
                    'aliasIds' => $aliasIds,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'merged_by' => $actorId,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($targetCustomerKey) ?? throw new RuntimeException('Merged customer profile could not be loaded.');
    }

    public function recentMerges(int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT m.id, m.target_customer_key, c.display_name AS target_name, m.source_display_name, m.merged_at
             FROM pickup_customer_merges m
             INNER JOIN pickup_customers c ON c.customer_key = m.target_customer_key
             WHERE m.undone_at IS NULL
             ORDER BY m.merged_at DESC, m.id DESC
             LIMIT :limit',
        );
        $statement->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'targetCustomerKey' => (string) $row['target_customer_key'],
            'targetName' => (string) $row['target_name'],
            'sourceName' => (string) $row['source_display_name'],
            'mergedAt' => (string) $row['merged_at'],
        ], $statement->fetchAll());
    }

    public function undoMerge(int $mergeId, string $actorId): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $mergeStatement = $this->connection->prepare(
                'SELECT target_customer_key, source_customer_key, snapshot
                 FROM pickup_customer_merges
                 WHERE id = :id AND undone_at IS NULL
                 FOR UPDATE',
            );
            $mergeStatement->execute(['id' => $mergeId]);
            $merge = $mergeStatement->fetch();
            if (!is_array($merge)) {
                throw new InvalidArgumentException('This merge has already been undone or no longer exists.');
            }
            $targetKey = (string) $merge['target_customer_key'];
            $sourceKey = (string) $merge['source_customer_key'];
            $snapshot = json_decode((string) $merge['snapshot'], true, 16, JSON_THROW_ON_ERROR);
            $source = is_array($snapshot['source'] ?? null) ? $snapshot['source'] : null;
            if ($source === null) {
                throw new RuntimeException('The merge snapshot is incomplete.');
            }

            $targetStatement = $this->connection->prepare(
                'SELECT customer_key, display_name, contact_name, email, phone, address, city, status, notes, next_follow_up_on
                 FROM pickup_customers WHERE customer_key = :customer_key FOR UPDATE',
            );
            $targetStatement->execute(['customer_key' => $targetKey]);
            $target = $targetStatement->fetch();
            if (!is_array($target)) {
                throw new InvalidArgumentException('The retained profile was merged again or removed. Undo that later merge first.');
            }
            $sourceName = (string) $source['display_name'];
            $conflictStatement = $this->connection->prepare(
                'SELECT customer_key FROM pickup_customers
                 WHERE customer_key = :customer_key OR LOWER(TRIM(display_name)) = LOWER(TRIM(:display_name))
                 LIMIT 1',
            );
            $conflictStatement->execute(['customer_key' => $sourceKey, 'display_name' => $sourceName]);
            if ($conflictStatement->fetchColumn() !== false) {
                throw new InvalidArgumentException(sprintf('Another customer profile now uses the name %s. Rename it before undoing this merge.', $sourceName));
            }

            $this->connection->prepare(
                'DELETE FROM pickup_customer_aliases WHERE alias_name = TRIM(:alias_name) AND customer_key = :customer_key',
            )->execute(['alias_name' => $sourceName, 'customer_key' => $targetKey]);

            $columns = ['customer_key', 'display_name', 'contact_name', 'email', 'phone', 'address', 'city', 'country_code',
                'status', 'notes', 'next_follow_up_on', 'source', 'assigned_role', 'created_by', 'updated_by', 'created_at'];
            $this->connection->prepare(
                'INSERT INTO pickup_customers (' . implode(', ', $columns) . ', updated_at)
                 VALUES (:' . implode(', :', $columns) . ', UTC_TIMESTAMP())',
            )->execute(array_combine($columns, array_map(
                static fn (string $column): mixed => $column === 'updated_by' ? $actorId : ($source[$column] ?? null),
                $columns,
            )));

            $aliasStatement = $this->connection->prepare(
                'UPDATE pickup_customer_aliases SET customer_key = :source_key WHERE id = :id AND customer_key = :target_key',
            );
            foreach (is_array($snapshot['aliasIds'] ?? null) ? $snapshot['aliasIds'] : [] as $aliasId) {
                $aliasStatement->execute(['source_key' => $sourceKey, 'id' => (int) $aliasId, 'target_key' => $targetKey]);
            }
            $rewardStatement = $this->connection->prepare(
                'UPDATE pickup_customer_reward_adjustments SET customer_key = :source_key WHERE id = :id AND customer_key = :target_key',
            );
            foreach (is_array($snapshot['rewardIds'] ?? null) ? $snapshot['rewardIds'] : [] as $rewardId) {
                $rewardStatement->execute(['source_key' => $sourceKey, 'id' => (int) $rewardId, 'target_key' => $targetKey]);
            }
            // Only shipments still attributed to the retained profile are handed back; rows edited to
            // another sender since the merge are left alone.
            $shipmentStatement = $this->connection->prepare(
                'UPDATE pickup_shipments
                 SET consignor = :previous_consignor
                 WHERE pickup_sheet_id = :pickup_sheet_id AND line_number = :line_number
                   AND LOWER(TRIM(consignor)) = LOWER(TRIM(:target_name))',
            );
            foreach (is_array($snapshot['shipments'] ?? null) ? $snapshot['shipments'] : [] as $shipment) {
                if (!is_array($shipment) || count($shipment) !== 3) {
                    continue;
                }
                $shipmentStatement->execute([
                    'previous_consignor' => (string) $shipment[2],
                    'pickup_sheet_id' => (int) $shipment[0],
                    'line_number' => (int) $shipment[1],
                    'target_name' => (string) $target['display_name'],
                ]);
            }

            // Restore target fields only where nobody has edited them since the merge.
            $current = $this->mergeFields($target);
            $before = is_array($snapshot['targetBefore'] ?? null) ? $snapshot['targetBefore'] : [];
            $after = is_array($snapshot['targetAfter'] ?? null) ? $snapshot['targetAfter'] : [];
            $restored = $current;
            foreach (CustomerMergePolicy::MERGED_FIELDS as $field) {
                if (array_key_exists($field, $before) && (string) ($current[$field] ?? '') === (string) ($after[$field] ?? '')) {
                    $restored[$field] = $before[$field];
                }
            }
            if ($restored !== $current) {
                $this->writeMergeFields($targetKey, $restored, $actorId);
            }

            $this->connection->prepare(
                'UPDATE pickup_customer_merges SET undone_by = :actor_id, undone_at = UTC_TIMESTAMP() WHERE id = :id',
            )->execute(['actor_id' => $actorId, 'id' => $mergeId]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException('The original profile name is in use again. Rename that profile before undoing this merge.', 0, $exception);
            }
            throw $exception;
        }

        return $this->find($sourceKey) ?? throw new RuntimeException('Restored customer profile could not be loaded.');
    }

    public function rewardAdjustments(string $customerKey, int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT points_delta, reason, actor_id, created_at
             FROM pickup_customer_reward_adjustments
             WHERE customer_key = :customer_key
             ORDER BY created_at DESC, id DESC
             LIMIT :limit',
        );
        $statement->bindValue(':customer_key', $customerKey);
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'pointsDelta' => (int) $row['points_delta'],
            'reason' => (string) $row['reason'],
            'actorId' => (string) $row['actor_id'],
            'createdAt' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    public function rewardRedemptions(string $customerKey, int $limit, int $offset = 0): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT points_delta, reason, actor_id, created_at
             FROM pickup_customer_reward_adjustments
             WHERE customer_key = :customer_key AND points_delta < 0
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':customer_key', $customerKey);
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'pointsDelta' => (int) $row['points_delta'],
            'reason' => (string) $row['reason'],
            'actorId' => (string) $row['actor_id'],
            'createdAt' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    public function rewardRedemptionCount(string $customerKey): int
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM pickup_customer_reward_adjustments
             WHERE customer_key = :customer_key AND points_delta < 0',
        );
        $statement->execute(['customer_key' => $customerKey]);
        return (int) $statement->fetchColumn();
    }

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
    ): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $customerStatement = $this->connection->prepare(
                'SELECT display_name FROM pickup_customers WHERE customer_key = :customer_key LIMIT 1 FOR UPDATE',
            );
            $customerStatement->execute(['customer_key' => $customerKey]);
            $customerDisplayName = $customerStatement->fetchColumn();
            if (!is_string($customerDisplayName)) {
                throw new RuntimeException('Customer profile not found for reward adjustment.');
            }

            $balanceStatement = $this->connection->prepare(
                'SELECT
                    (SELECT FLOOR(COALESCE(SUM(ps.weight_kg), 0) * 10)
                     FROM pickup_shipments ps
                     INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                     WHERE p.deleted_at IS NULL
                       AND LOWER(TRIM(ps.consignor)) = LOWER(TRIM(:shipment_customer_name)))
                    +
                    (SELECT COALESCE(SUM(points_delta), 0)
                     FROM pickup_customer_reward_adjustments
                     WHERE customer_key = :reward_customer_key) AS reward_balance',
            );
            $balanceStatement->execute([
                'shipment_customer_name' => $customerDisplayName,
                'reward_customer_key' => $customerKey,
            ]);
            $balance = (int) $balanceStatement->fetchColumn();
            if ($balance + $pointsDelta < 0) {
                throw new InvalidArgumentException('A redemption cannot exceed the available reward balance.');
            }

            $statement = $this->connection->prepare(
                'INSERT INTO pickup_customer_reward_adjustments
                    (customer_key, points_delta, reason, actor_id, created_at)
                 VALUES (:customer_key, :points_delta, :reason, :actor_id, UTC_TIMESTAMP())',
            );
            $statement->execute([
                'customer_key' => $customerKey,
                'points_delta' => $pointsDelta,
                'reason' => $reason,
                'actor_id' => $actorId,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($customerKey) ?? throw new RuntimeException('Updated customer rewards could not be loaded.');
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function filters(string $search, string $status): array
    {
        $conditions = [];
        $parameters = [];
        if ($search !== '') {
            $conditions[] = '(c.display_name LIKE :search OR c.contact_name LIKE :search_contact OR c.email LIKE :search_email OR c.phone LIKE :search_phone OR c.city LIKE :search_city)';
            $like = '%' . $search . '%';
            $parameters = [
                'search' => $like,
                'search_contact' => $like,
                'search_email' => $like,
                'search_phone' => $like,
                'search_city' => $like,
            ];
        }
        if ($status !== '') {
            $conditions[] = 'c.status = :status';
            $parameters['status'] = $status;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    private function customerSelect(): string
    {
        return "SELECT c.id, c.customer_key, c.display_name, c.contact_name, c.email, c.phone,
                       c.address, c.city, c.country_code, c.status, c.notes, c.next_follow_up_on,
                       c.source, c.created_at, c.updated_at,
                       COALESCE(metrics.shipment_count, 0) AS shipment_count,
                       COALESCE(metrics.total_cash_xaf, 0) AS total_cash_xaf,
                       COALESCE(metrics.cargo_reward_points, 0) AS cargo_reward_points,
                       metrics.first_shipment_on, metrics.last_shipment_on,
                       COALESCE(rewards.adjustment_points, 0) AS reward_adjustment_points,
                       COALESCE(rewards.earned_adjustment_points, 0) AS reward_earned_adjustment_points
                FROM pickup_customers c
                LEFT JOIN (
                    SELECT LOWER(TRIM(ps.consignor)) AS customer_name,
                           COUNT(*) AS shipment_count,
                           COALESCE(SUM(ps.amount_xaf), 0) AS total_cash_xaf,
                           FLOOR(COALESCE(SUM(ps.weight_kg), 0) * 10) AS cargo_reward_points,
                           MIN(p.collection_date) AS first_shipment_on,
                           MAX(p.collection_date) AS last_shipment_on
                    FROM pickup_shipments ps
                    INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                    WHERE p.deleted_at IS NULL
                    GROUP BY LOWER(TRIM(ps.consignor))
                ) metrics ON metrics.customer_name = LOWER(TRIM(c.display_name))
                LEFT JOIN (
                    SELECT customer_key,
                           COALESCE(SUM(points_delta), 0) AS adjustment_points,
                           COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END), 0) AS earned_adjustment_points
                    FROM pickup_customer_reward_adjustments
                    GROUP BY customer_key
                ) rewards ON rewards.customer_key = c.customer_key";
    }

    private function profile(array $row): CustomerProfile
    {
        return new CustomerProfile(
            (int) $row['id'],
            (string) $row['customer_key'],
            (string) $row['display_name'],
            (string) ($row['contact_name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['phone'] ?? ''),
            (string) ($row['address'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['country_code'] ?? ''),
            (string) $row['status'],
            (string) ($row['notes'] ?? ''),
            isset($row['next_follow_up_on']) ? (string) $row['next_follow_up_on'] : null,
            (string) $row['source'],
            (int) ($row['shipment_count'] ?? 0),
            (int) ($row['total_cash_xaf'] ?? 0),
            isset($row['first_shipment_on']) ? (string) $row['first_shipment_on'] : null,
            isset($row['last_shipment_on']) ? (string) $row['last_shipment_on'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            (int) ($row['reward_adjustment_points'] ?? 0),
            (int) ($row['reward_earned_adjustment_points'] ?? 0),
            (int) ($row['cargo_reward_points'] ?? 0),
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->connection->exec(
            "CREATE TABLE IF NOT EXISTS pickup_customers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key CHAR(64) NOT NULL,
                display_name VARCHAR(160) NOT NULL,
                contact_name VARCHAR(100) NULL,
                email VARCHAR(254) NULL,
                phone VARCHAR(32) NULL,
                address VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                country_code CHAR(2) NOT NULL DEFAULT 'CM',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                next_follow_up_on DATE NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                assigned_role VARCHAR(20) NOT NULL DEFAULT 'admin',
                created_by CHAR(24) NULL,
                updated_by CHAR(24) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE INDEX pickup_customers_key_idx (customer_key),
                UNIQUE INDEX pickup_customers_name_unique_idx (display_name),
                INDEX pickup_customers_status_follow_up_idx (status, next_follow_up_on),
                INDEX pickup_customers_email_idx (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_customer_reward_adjustments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key CHAR(64) NOT NULL,
                points_delta INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                actor_id CHAR(24) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX pickup_customer_rewards_customer_time_idx (customer_key, created_at),
                INDEX pickup_customer_rewards_actor_time_idx (actor_id, created_at),
                CONSTRAINT pickup_customer_rewards_customer_fk
                    FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_customer_aliases (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key CHAR(64) NOT NULL,
                alias_name VARCHAR(160) NOT NULL,
                created_by CHAR(24) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE INDEX pickup_customer_aliases_name_idx (alias_name),
                INDEX pickup_customer_aliases_customer_idx (customer_key),
                CONSTRAINT pickup_customer_aliases_customer_fk
                    FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_customer_merges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                target_customer_key CHAR(64) NOT NULL,
                source_customer_key CHAR(64) NOT NULL,
                target_display_name VARCHAR(160) NOT NULL,
                source_display_name VARCHAR(160) NOT NULL,
                snapshot MEDIUMTEXT NOT NULL,
                merged_by CHAR(24) NOT NULL,
                merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                undone_by CHAR(24) NULL,
                undone_at DATETIME NULL,
                INDEX pickup_customer_merges_open_idx (undone_at, merged_at),
                INDEX pickup_customer_merges_target_idx (target_customer_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        try {
            $this->connection->query('SELECT assigned_role FROM pickup_customers LIMIT 1');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1054) {
                throw $exception;
            }
            $this->connection->exec(
                "ALTER TABLE pickup_customers
                 ADD COLUMN assigned_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER source",
            );
        }
        $this->connection->exec("UPDATE pickup_customers SET country_code = 'CM' WHERE country_code IS NULL OR country_code <> 'CM'");
        $this->connection->exec("UPDATE pickup_customers SET assigned_role = 'admin' WHERE assigned_role <> 'admin'");
        $this->schemaReady = true;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{contactName: string, email: string, phone: string, address: string, city: string, status: string, nextFollowUpOn: ?string, notes: string}
     */
    private function mergeFields(array $row): array
    {
        return [
            'contactName' => (string) ($row['contact_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'nextFollowUpOn' => isset($row['next_follow_up_on']) && $row['next_follow_up_on'] !== '' ? (string) $row['next_follow_up_on'] : null,
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $fields */
    private function writeMergeFields(string $customerKey, array $fields, string $actorId): void
    {
        $this->connection->prepare(
            'UPDATE pickup_customers
             SET contact_name = :contact_name, email = :email, phone = :phone, address = :address,
                 city = :city, status = :status, notes = :notes, next_follow_up_on = :next_follow_up_on,
                 updated_by = :updated_by, updated_at = UTC_TIMESTAMP()
             WHERE customer_key = :customer_key',
        )->execute([
            'contact_name' => $this->nullable((string) ($fields['contactName'] ?? '')),
            'email' => $this->nullable((string) ($fields['email'] ?? '')),
            'phone' => $this->nullable((string) ($fields['phone'] ?? '')),
            'address' => $this->nullable((string) ($fields['address'] ?? '')),
            'city' => $this->nullable((string) ($fields['city'] ?? '')),
            'status' => (string) ($fields['status'] ?? 'active'),
            'notes' => $this->nullable((string) ($fields['notes'] ?? '')),
            'next_follow_up_on' => is_string($fields['nextFollowUpOn'] ?? null) && $fields['nextFollowUpOn'] !== '' ? $fields['nextFollowUpOn'] : null,
            'updated_by' => $actorId,
            'customer_key' => $customerKey,
        ]);
    }

    /** @return list<int> */
    private function idsWhere(string $table, string $customerKey): array
    {
        $statement = $this->connection->prepare('SELECT id FROM ' . $table . ' WHERE customer_key = :customer_key ORDER BY id FOR UPDATE');
        $statement->execute(['customer_key' => $customerKey]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
