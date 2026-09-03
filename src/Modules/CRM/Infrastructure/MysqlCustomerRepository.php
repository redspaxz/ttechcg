<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

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
        $this->connection->exec(
            "INSERT INTO pickup_customers
                (customer_key, display_name, country_code, status, source, assigned_role, created_at, updated_at)
             SELECT SHA2(LOWER(TRIM(ps.consignor)), 256), MIN(TRIM(ps.consignor)), 'CM', 'active', 'shipment', 'admin', UTC_TIMESTAMP(), UTC_TIMESTAMP()
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             LEFT JOIN pickup_customers existing_customer
               ON LOWER(TRIM(existing_customer.display_name)) = LOWER(TRIM(ps.consignor))
             WHERE p.deleted_at IS NULL
               AND TRIM(ps.consignor) <> ''
               AND existing_customer.id IS NULL
             GROUP BY SHA2(LOWER(TRIM(ps.consignor)), 256)
             ON DUPLICATE KEY UPDATE customer_key = VALUES(customer_key)",
        );
        $this->synchronized = true;
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

    public function find(string $customerKey): ?CustomerProfile
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare($this->customerSelect() . ' WHERE c.customer_key = :customer_key LIMIT 1');
        $statement->execute(['customer_key' => $customerKey]);
        $row = $statement->fetch();
        return is_array($row) ? $this->profile($row) : null;
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
                'SELECT display_name FROM pickup_customers WHERE customer_key = :customer_key LIMIT 1 FOR UPDATE',
            );
            $existingStatement->execute(['customer_key' => $customer->customerKey]);
            $previousDisplayName = $existingStatement->fetchColumn();

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

            $statement = $this->connection->prepare(
                'INSERT INTO pickup_customers
                    (customer_key, display_name, contact_name, email, phone, address, city, country_code,
                     status, notes, next_follow_up_on, source, assigned_role, created_by, updated_by, created_at, updated_at)
                 VALUES
                    (:customer_key, :display_name, :contact_name, :email, :phone, :address, :city, :country_code,
                     :status, :notes, :next_follow_up_on, :source, :assigned_role, :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name), contact_name = VALUES(contact_name), email = VALUES(email),
                    phone = VALUES(phone), address = VALUES(address), city = VALUES(city), country_code = VALUES(country_code),
                    status = VALUES(status), notes = VALUES(notes), next_follow_up_on = VALUES(next_follow_up_on),
                    source = VALUES(source), assigned_role = VALUES(assigned_role), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
            );
            $statement->execute([
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
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($customer->customerKey) ?? $customer;
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
                INDEX pickup_customers_name_idx (display_name),
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
}
