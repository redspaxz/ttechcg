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
    /** Actor recorded for changes the application makes on its own, such as alias resolution. */
    private const SYSTEM_ACTOR_ID = '000000000000000000000000';
    private const SYNC_NAME = 'shipments';
    private const PROFILE_CHANGED_MESSAGE = 'This profile was changed by someone else after you opened it. Your changes were not saved; review the current details and edit again.';

    private bool $schemaReady = false;
    private bool $synchronized = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Creates profiles for new consignors. Only shipment rows added since the last run are read, so
     * CRM pages do not rescan every sheet; a profile that was deleted is not recreated from old sheets.
     */
    public function synchronizeFromShipments(): void
    {
        if ($this->synchronized) {
            return;
        }
        $this->ensureSchema();
        $latestShipmentId = (int) $this->connection->query('SELECT COALESCE(MAX(id), 0) FROM pickup_shipments')->fetchColumn();
        $stateStatement = $this->connection->prepare('SELECT last_shipment_id FROM pickup_crm_sync_state WHERE sync_name = :sync_name');
        $stateStatement->execute(['sync_name' => self::SYNC_NAME]);
        $lastShipmentId = (int) ($stateStatement->fetchColumn() ?: 0);
        if ($latestShipmentId > $lastShipmentId) {
            $this->resolveShipmentAliases($lastShipmentId, $latestShipmentId);
            // Group by the column collation (case- and accent-insensitive) so spelling variants that
            // the unique display-name index treats as equal become one profile. A name-derived key
            // still held by a renamed profile falls back to a random key instead of dropping the sender.
            $insertStatement = $this->connection->prepare(
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
                       ON existing_customer.display_name = TRIM(ps.consignor)
                     WHERE ps.id > :after_shipment_id AND ps.id <= :through_shipment_id
                       AND p.deleted_at IS NULL
                       AND TRIM(ps.consignor) <> ''
                       AND existing_customer.id IS NULL
                     GROUP BY LOWER(TRIM(ps.consignor))
                 ) candidates
                 LEFT JOIN pickup_customers key_owner ON key_owner.customer_key = candidates.name_key
                 ON DUPLICATE KEY UPDATE pickup_customers.id = pickup_customers.id",
            );
            $insertStatement->execute(['after_shipment_id' => $lastShipmentId, 'through_shipment_id' => $latestShipmentId]);
            $this->connection->prepare(
                'INSERT INTO pickup_crm_sync_state (sync_name, last_shipment_id, updated_at)
                 VALUES (:sync_name, :last_shipment_id, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE last_shipment_id = GREATEST(last_shipment_id, VALUES(last_shipment_id)), updated_at = UTC_TIMESTAMP()',
            )->execute(['sync_name' => self::SYNC_NAME, 'last_shipment_id' => $latestShipmentId]);
        }
        $this->synchronized = true;
    }

    /** Points new shipments still typed with a merged-away name at the profile that absorbed it. */
    private function resolveShipmentAliases(int $afterShipmentId, int $throughShipmentId): void
    {
        if ($this->connection->query('SELECT 1 FROM pickup_customer_aliases LIMIT 1')->fetchColumn() === false) {
            return;
        }
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT ps.id, ps.pickup_sheet_id, p.reference_number, ps.line_number, ps.consignor, c.display_name AS new_consignor
                 FROM pickup_shipments ps
                 INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                 INNER JOIN pickup_customer_aliases alias_map ON alias_map.alias_name = TRIM(ps.consignor)
                 INNER JOIN pickup_customers c ON c.customer_key = alias_map.customer_key
                 WHERE ps.id > :after_shipment_id AND ps.id <= :through_shipment_id
                 FOR UPDATE',
            );
            $statement->execute(['after_shipment_id' => $afterShipmentId, 'through_shipment_id' => $throughShipmentId]);
            $update = $this->connection->prepare('UPDATE pickup_shipments SET consignor = :consignor WHERE id = :id');
            $changes = [];
            foreach ($statement->fetchAll() as $row) {
                $update->execute(['consignor' => (string) $row['new_consignor'], 'id' => (int) $row['id']]);
                $changes[] = $this->consignorChange($row, (string) $row['new_consignor']);
            }
            $this->auditConsignorChanges($changes, 'crm_alias_resolution', self::SYSTEM_ACTOR_ID);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function paginated(array $filters, int $limit, int $offset): array
    {
        $this->ensureSchema();
        [$where, $parameters] = $this->filters($filters);
        $countStatement = $this->connection->prepare('SELECT COUNT(*) FROM pickup_customers c' . $where);
        $countStatement->execute($parameters);
        $totalRecords = (int) $countStatement->fetchColumn();

        $orderBy = match ($filters['sort'] ?? 'priority') {
            'name' => 'LOWER(c.display_name) ASC, c.display_name ASC',
            'last_shipment' => "COALESCE(metrics.last_shipment_on, '0000-00-00') DESC, LOWER(c.display_name) ASC",
            'value' => 'COALESCE(metrics.total_cash_xaf, 0) DESC, LOWER(c.display_name) ASC',
            'points' => 'GREATEST(0, COALESCE(metrics.cargo_reward_points, 0) + COALESCE(rewards.adjustment_points, 0)) DESC, LOWER(c.display_name) ASC',
            default => "CASE WHEN c.next_follow_up_on IS NOT NULL AND c.next_follow_up_on <= UTC_DATE() AND c.status <> 'inactive' THEN 0 ELSE 1 END,
                    CASE c.status WHEN 'attention' THEN 0 WHEN 'lead' THEN 1 WHEN 'active' THEN 2 ELSE 3 END,
                    COALESCE(metrics.last_shipment_on, '0000-00-00') DESC,
                    c.display_name ASC",
        };
        $statement = $this->connection->prepare(
            $this->customerSelect() . $where . ' ORDER BY ' . $orderBy . ' LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', max(1, min($limit, 5000)), PDO::PARAM_INT);
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
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ), static fn (string $name): bool => $name !== ''));
    }

    public function duplicateReviewNames(int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare('SELECT customer_key, display_name, email, phone FROM pickup_customers ORDER BY id LIMIT :limit');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'customerKey' => (string) $row['customer_key'],
            'displayName' => (string) $row['display_name'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
        ], $statement->fetchAll());
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        $this->ensureSchema();
        // Totals are aggregated for this one customer only, using the consignor index.
        $statement = $this->connection->prepare($this->customerSelect(true) . ' WHERE c.customer_key = :customer_key LIMIT 1');
        $statement->execute([
            'customer_key' => $customerKey,
            'metrics_customer_key' => $customerKey,
            'rewards_customer_key' => $customerKey,
        ]);
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
               AND ps.consignor = c.display_name
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
               AND ps.consignor = c.display_name',
        );
        $statement->execute(['customer_key' => $customerKey]);
        return (int) $statement->fetchColumn();
    }

    public function save(CustomerProfile $customer, string $actorId, ?string $expectedUpdatedAt = null): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $existingStatement = $this->connection->prepare(
                'SELECT id, display_name, updated_at FROM pickup_customers WHERE customer_key = :customer_key LIMIT 1 FOR UPDATE',
            );
            $existingStatement->execute(['customer_key' => $customer->customerKey]);
            $existingRow = $existingStatement->fetch();
            if ($customer->id === null && is_array($existingRow)) {
                throw new InvalidArgumentException('A customer profile already uses this organization name.');
            }
            if ($customer->id !== null && !is_array($existingRow)) {
                throw new InvalidArgumentException('Customer profile not found.');
            }
            if ($expectedUpdatedAt !== null && is_array($existingRow) && (string) $existingRow['updated_at'] !== $expectedUpdatedAt) {
                throw new InvalidArgumentException(self::PROFILE_CHANGED_MESSAGE);
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
                $this->renameConsignors($previousDisplayName, $customer->displayName, 'crm_customer_rename', $actorId);
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
                'assigned_actor_id' => $customer->assignedActorId,
                'assigned_name' => $customer->assignedActorId === null ? null : $this->nullable($customer->assignedName),
                'updated_by' => $actorId,
            ];
            if ($customer->id === null) {
                $statement = $this->connection->prepare(
                    'INSERT INTO pickup_customers
                        (customer_key, display_name, contact_name, email, phone, address, city, country_code,
                         status, notes, next_follow_up_on, source, assigned_role, assigned_actor_id, assigned_name,
                         created_by, updated_by, created_at, updated_at)
                     VALUES
                        (:customer_key, :display_name, :contact_name, :email, :phone, :address, :city, :country_code,
                         :status, :notes, :next_follow_up_on, :source, :assigned_role, :assigned_actor_id, :assigned_name,
                         :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                );
                $parameters['created_by'] = $actorId;
            } else {
                $statement = $this->connection->prepare(
                    'UPDATE pickup_customers
                     SET display_name = :display_name, contact_name = :contact_name, email = :email,
                         phone = :phone, address = :address, city = :city, country_code = :country_code,
                         status = :status, notes = :notes, next_follow_up_on = :next_follow_up_on,
                         source = :source, assigned_role = :assigned_role, assigned_actor_id = :assigned_actor_id,
                         assigned_name = :assigned_name, updated_by = :updated_by, updated_at = UTC_TIMESTAMP()
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

    public function delete(string $customerKey, string $actorId): void
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare('SELECT id FROM pickup_customers WHERE customer_key = :customer_key FOR UPDATE');
            $statement->execute(['customer_key' => $customerKey]);
            if ($statement->fetchColumn() === false) {
                throw new InvalidArgumentException('Customer profile not found.');
            }
            // Merge snapshots hold copies of the profile's personal data, so they go too.
            $this->connection->prepare(
                'DELETE FROM pickup_customer_merges WHERE target_customer_key = :target_key OR source_customer_key = :source_key',
            )->execute(['target_key' => $customerKey, 'source_key' => $customerKey]);
            // Reward adjustments, aliases, and activities are removed by their foreign-key cascades.
            $this->connection->prepare('DELETE FROM pickup_customers WHERE customer_key = :customer_key')
                ->execute(['customer_key' => $customerKey]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function activities(string $customerKey, int $limit): array
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'SELECT activity_type, occurred_on, summary, actor_id, actor_name, created_at
             FROM pickup_customer_activities
             WHERE customer_key = :customer_key
             ORDER BY occurred_on DESC, id DESC
             LIMIT :limit',
        );
        $statement->bindValue(':customer_key', $customerKey);
        $statement->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'type' => (string) $row['activity_type'],
            'occurredOn' => (string) $row['occurred_on'],
            'summary' => (string) $row['summary'],
            'actorId' => (string) $row['actor_id'],
            'actorName' => (string) $row['actor_name'],
            'createdAt' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    public function addActivity(
        string $customerKey,
        string $type,
        string $occurredOn,
        string $summary,
        ?string $nextFollowUpOn,
        string $actorId,
        string $actorName,
    ): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare('SELECT id FROM pickup_customers WHERE customer_key = :customer_key FOR UPDATE');
            $statement->execute(['customer_key' => $customerKey]);
            if ($statement->fetchColumn() === false) {
                throw new InvalidArgumentException('Customer profile not found.');
            }
            $this->connection->prepare(
                'INSERT INTO pickup_customer_activities
                    (customer_key, activity_type, occurred_on, summary, actor_id, actor_name, created_at)
                 VALUES (:customer_key, :activity_type, :occurred_on, :summary, :actor_id, :actor_name, UTC_TIMESTAMP())',
            )->execute([
                'customer_key' => $customerKey,
                'activity_type' => $type,
                'occurred_on' => $occurredOn,
                'summary' => $summary,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
            ]);
            $this->connection->prepare(
                'UPDATE pickup_customers
                 SET next_follow_up_on = :next_follow_up_on, updated_by = :updated_by, updated_at = UTC_TIMESTAMP()
                 WHERE customer_key = :customer_key',
            )->execute(['next_follow_up_on' => $nextFollowUpOn, 'updated_by' => $actorId, 'customer_key' => $customerKey]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($customerKey) ?? throw new RuntimeException('Customer profile could not be loaded.');
    }

    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile
    {
        $this->ensureSchema();
        $this->connection->beginTransaction();
        try {
            $profileStatement = $this->connection->prepare(
                'SELECT customer_key, display_name, contact_name, email, phone, address, city, country_code,
                        status, notes, next_follow_up_on, source, assigned_role, assigned_actor_id, assigned_name,
                        created_by, updated_by, created_at, updated_at
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

            $shipments = array_map(
                static fn (array $change): array => [$change['sheetId'], $change['lineNumber'], $change['before']],
                $this->renameConsignors((string) $source['display_name'], (string) $target['display_name'], 'crm_customer_merge', $actorId),
            );

            $rewardIds = $this->idsWhere('pickup_customer_reward_adjustments', $sourceCustomerKey);
            $this->connection->prepare(
                'UPDATE pickup_customer_reward_adjustments SET customer_key = :target_key WHERE customer_key = :source_key',
            )->execute(['target_key' => $targetCustomerKey, 'source_key' => $sourceCustomerKey]);

            $aliasIds = $this->idsWhere('pickup_customer_aliases', $sourceCustomerKey);
            $this->connection->prepare(
                'UPDATE pickup_customer_aliases SET customer_key = :target_key WHERE customer_key = :source_key',
            )->execute(['target_key' => $targetCustomerKey, 'source_key' => $sourceCustomerKey]);

            $activityIds = $this->idsWhere('pickup_customer_activities', $sourceCustomerKey);
            $this->connection->prepare(
                'UPDATE pickup_customer_activities SET customer_key = :target_key WHERE customer_key = :source_key',
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
                    'activityIds' => $activityIds,
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
                'status', 'notes', 'next_follow_up_on', 'source', 'assigned_role', 'assigned_actor_id', 'assigned_name',
                'created_by', 'updated_by', 'created_at'];
            $this->connection->prepare(
                'INSERT INTO pickup_customers (' . implode(', ', $columns) . ', updated_at)
                 VALUES (:' . implode(', :', $columns) . ', UTC_TIMESTAMP())',
            )->execute(array_combine($columns, array_map(
                static fn (string $column): mixed => $column === 'updated_by' ? $actorId : ($source[$column] ?? null),
                $columns,
            )));

            foreach ([
                'pickup_customer_aliases' => 'aliasIds',
                'pickup_customer_reward_adjustments' => 'rewardIds',
                'pickup_customer_activities' => 'activityIds',
            ] as $table => $snapshotKey) {
                $moveBack = $this->connection->prepare(
                    'UPDATE ' . $table . ' SET customer_key = :source_key WHERE id = :id AND customer_key = :target_key',
                );
                foreach (is_array($snapshot[$snapshotKey] ?? null) ? $snapshot[$snapshotKey] : [] as $rowId) {
                    $moveBack->execute(['source_key' => $sourceKey, 'id' => (int) $rowId, 'target_key' => $targetKey]);
                }
            }

            // Only shipments still attributed to the retained profile are handed back; rows edited to
            // another sender since the merge are left alone.
            $shipmentStatement = $this->connection->prepare(
                'SELECT ps.id, ps.pickup_sheet_id, p.reference_number, ps.line_number, ps.consignor
                 FROM pickup_shipments ps
                 INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                 WHERE ps.pickup_sheet_id = :pickup_sheet_id AND ps.line_number = :line_number
                   AND ps.consignor = :target_name
                 FOR UPDATE',
            );
            $restoreStatement = $this->connection->prepare('UPDATE pickup_shipments SET consignor = :consignor WHERE id = :id');
            $changes = [];
            foreach (is_array($snapshot['shipments'] ?? null) ? $snapshot['shipments'] : [] as $shipment) {
                if (!is_array($shipment) || count($shipment) !== 3) {
                    continue;
                }
                $shipmentStatement->execute([
                    'pickup_sheet_id' => (int) $shipment[0],
                    'line_number' => (int) $shipment[1],
                    'target_name' => (string) $target['display_name'],
                ]);
                $row = $shipmentStatement->fetch();
                if (!is_array($row)) {
                    continue;
                }
                $restoreStatement->execute(['consignor' => (string) $shipment[2], 'id' => (int) $row['id']]);
                $changes[] = $this->consignorChange($row, (string) $shipment[2]);
            }
            $this->auditConsignorChanges($changes, 'crm_customer_merge_undo', $actorId);

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
                       AND ps.consignor = :shipment_customer_name)
                    +
                    (SELECT COALESCE(SUM(points_delta), 0)
                     FROM pickup_customer_reward_adjustments
                     WHERE customer_key = :reward_customer_key) AS reward_balance',
            );
            $balanceStatement->execute([
                'shipment_customer_name' => $customerDisplayName,
                'reward_customer_key' => $customerKey,
            ]);
            // Bonuses are always allowed. A redemption is checked against the visible balance, which
            // never drops below zero even when points were redeemed on a sheet that was later deleted.
            $availableBalance = max(0, (int) $balanceStatement->fetchColumn());
            if ($pointsDelta < 0 && $availableBalance + $pointsDelta < 0) {
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

    /**
     * @param array{search?: string, status?: string, followUp?: string, owner?: string, sort?: string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function filters(array $filters): array
    {
        $conditions = [];
        $parameters = [];
        $search = (string) ($filters['search'] ?? '');
        if ($search !== '') {
            // "!" escapes LIKE wildcards so "%" and "_" are searched for literally.
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $conditions[] = "(c.display_name LIKE :search ESCAPE '!' OR c.contact_name LIKE :search_contact ESCAPE '!'
                OR c.email LIKE :search_email ESCAPE '!' OR c.phone LIKE :search_phone ESCAPE '!' OR c.city LIKE :search_city ESCAPE '!'
                OR EXISTS (SELECT 1 FROM pickup_customer_aliases search_alias
                           WHERE search_alias.customer_key = c.customer_key AND search_alias.alias_name LIKE :search_alias ESCAPE '!'))";
            foreach (['search', 'search_contact', 'search_email', 'search_phone', 'search_city', 'search_alias'] as $name) {
                $parameters[$name] = $like;
            }
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $conditions[] = 'c.status = :status';
            $parameters['status'] = $status;
        }
        $conditions[] = match ((string) ($filters['followUp'] ?? '')) {
            'due' => "(c.next_follow_up_on IS NOT NULL AND c.next_follow_up_on <= UTC_DATE() AND c.status <> 'inactive')",
            'scheduled' => 'c.next_follow_up_on > UTC_DATE()',
            'none' => 'c.next_follow_up_on IS NULL',
            default => '',
        };
        $owner = (string) ($filters['owner'] ?? '');
        if ($owner === 'unassigned') {
            $conditions[] = 'c.assigned_actor_id IS NULL';
        } elseif (preg_match('/^[a-f0-9]{24}$/', $owner) === 1) {
            $conditions[] = 'c.assigned_actor_id = :owner';
            $parameters['owner'] = $owner;
        }
        $conditions = array_values(array_filter($conditions));

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /**
     * @param bool $singleCustomer aggregate totals for one customer (bind :metrics_customer_key and
     *                             :rewards_customer_key) instead of for every consignor
     */
    private function customerSelect(bool $singleCustomer = false): string
    {
        $metricsFilter = $singleCustomer
            ? ' AND ps.consignor = (SELECT metrics_customer.display_name FROM pickup_customers metrics_customer WHERE metrics_customer.customer_key = :metrics_customer_key)'
            : '';
        $rewardsFilter = $singleCustomer ? ' WHERE customer_key = :rewards_customer_key' : '';

        return "SELECT c.id, c.customer_key, c.display_name, c.contact_name, c.email, c.phone,
                       c.address, c.city, c.country_code, c.status, c.notes, c.next_follow_up_on,
                       c.source, c.assigned_actor_id, c.assigned_name, c.created_at, c.updated_at,
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
                    WHERE p.deleted_at IS NULL{$metricsFilter}
                    GROUP BY LOWER(TRIM(ps.consignor))
                ) metrics ON metrics.customer_name = LOWER(TRIM(c.display_name))
                LEFT JOIN (
                    SELECT customer_key,
                           COALESCE(SUM(points_delta), 0) AS adjustment_points,
                           COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END), 0) AS earned_adjustment_points
                    FROM pickup_customer_reward_adjustments{$rewardsFilter}
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
            isset($row['assigned_actor_id']) ? (string) $row['assigned_actor_id'] : null,
            (string) ($row['assigned_name'] ?? ''),
        );
    }

    /**
     * Renames every shipment consignor that matches $fromName and records one audit entry per
     * affected sheet, so CRM-driven changes appear in the sheet edit history.
     *
     * @return list<array{shipmentId: int, sheetId: int, reference: string, lineNumber: int, before: string, after: string}>
     */
    private function renameConsignors(string $fromName, string $toName, string $source, string $actorId): array
    {
        $statement = $this->connection->prepare(
            'SELECT ps.id, ps.pickup_sheet_id, p.reference_number, ps.line_number, ps.consignor
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE ps.consignor = TRIM(:previous_display_name)
             FOR UPDATE',
        );
        $statement->execute(['previous_display_name' => $fromName]);
        $changes = array_map(fn (array $row): array => $this->consignorChange($row, $toName), $statement->fetchAll());

        $this->connection->prepare(
            'UPDATE pickup_shipments
             SET consignor = :display_name
             WHERE consignor = TRIM(:previous_display_name)',
        )->execute(['display_name' => $toName, 'previous_display_name' => $fromName]);
        $this->auditConsignorChanges($changes, $source, $actorId);

        return $changes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{shipmentId: int, sheetId: int, reference: string, lineNumber: int, before: string, after: string}
     */
    private function consignorChange(array $row, string $after): array
    {
        return [
            'shipmentId' => (int) $row['id'],
            'sheetId' => (int) $row['pickup_sheet_id'],
            'reference' => (string) $row['reference_number'],
            'lineNumber' => (int) $row['line_number'],
            'before' => (string) $row['consignor'],
            'after' => $after,
        ];
    }

    /** @param list<array{shipmentId: int, sheetId: int, reference: string, lineNumber: int, before: string, after: string}> $changes */
    private function auditConsignorChanges(array $changes, string $source, string $actorId): void
    {
        $bySheet = [];
        foreach ($changes as $change) {
            if ($change['before'] !== $change['after']) {
                $bySheet[$change['sheetId']][] = $change;
            }
        }
        if ($bySheet === []) {
            return;
        }
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_sheet_edit_audit
                (pickup_sheet_id, reference_number, actor_id, before_snapshot, after_snapshot, created_at)
             VALUES (:pickup_sheet_id, :reference_number, :actor_id, :before_snapshot, :after_snapshot, UTC_TIMESTAMP())',
        );
        foreach ($bySheet as $sheetId => $sheetChanges) {
            $snapshot = static fn (string $side): string => json_encode([
                'reference_number' => $sheetChanges[0]['reference'],
                'change_source' => $source,
                'shipments' => array_map(static fn (array $change): array => [
                    'line_number' => $change['lineNumber'],
                    'consignor' => $change[$side],
                ], $sheetChanges),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $statement->execute([
                'pickup_sheet_id' => $sheetId,
                'reference_number' => $sheetChanges[0]['reference'],
                'actor_id' => $actorId,
                'before_snapshot' => $snapshot('before'),
                'after_snapshot' => $snapshot('after'),
            ]);
        }
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
                assigned_actor_id CHAR(24) NULL,
                assigned_name VARCHAR(160) NULL,
                created_by CHAR(24) NULL,
                updated_by CHAR(24) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE INDEX pickup_customers_key_idx (customer_key),
                UNIQUE INDEX pickup_customers_name_unique_idx (display_name),
                INDEX pickup_customers_status_follow_up_idx (status, next_follow_up_on),
                INDEX pickup_customers_email_idx (email),
                INDEX pickup_customers_assigned_actor_idx (assigned_actor_id)
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
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_customer_activities (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key CHAR(64) NOT NULL,
                activity_type VARCHAR(20) NOT NULL,
                occurred_on DATE NOT NULL,
                summary TEXT NOT NULL,
                actor_id CHAR(24) NOT NULL,
                actor_name VARCHAR(160) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX pickup_customer_activities_customer_idx (customer_key, occurred_on),
                CONSTRAINT pickup_customer_activities_customer_fk
                    FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_crm_sync_state (
                sync_name VARCHAR(40) NOT NULL PRIMARY KEY,
                last_shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->ensureColumn('assigned_role', "ALTER TABLE pickup_customers ADD COLUMN assigned_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER source");
        $this->ensureColumn(
            'assigned_actor_id',
            'ALTER TABLE pickup_customers
                ADD COLUMN assigned_actor_id CHAR(24) NULL AFTER assigned_role,
                ADD COLUMN assigned_name VARCHAR(160) NULL AFTER assigned_actor_id,
                ADD INDEX pickup_customers_assigned_actor_idx (assigned_actor_id)',
        );
        $this->connection->exec("UPDATE pickup_customers SET country_code = 'CM' WHERE country_code IS NULL OR country_code <> 'CM'");
        $this->connection->exec("UPDATE pickup_customers SET assigned_role = 'admin' WHERE assigned_role <> 'admin'");
        $this->schemaReady = true;
    }

    private function ensureColumn(string $column, string $alterStatement): void
    {
        try {
            $this->connection->query('SELECT ' . $column . ' FROM pickup_customers LIMIT 1');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1054) {
                throw $exception;
            }
            $this->connection->exec($alterStatement);
        }
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
