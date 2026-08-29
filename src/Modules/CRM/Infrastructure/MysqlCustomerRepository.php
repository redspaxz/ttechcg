<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use PDO;

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
                (customer_key, display_name, status, source, created_at, updated_at)
             SELECT SHA2(LOWER(TRIM(ps.consignor)), 256), MIN(TRIM(ps.consignor)), 'active', 'shipment', UTC_TIMESTAMP(), UTC_TIMESTAMP()
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE p.deleted_at IS NULL AND TRIM(ps.consignor) <> ''
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

    public function recentShipments(string $customerKey, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT p.reference_number, p.collection_date, ps.awb_number, ps.destination,
                    ps.amount_xaf, COALESCE(p.status, 'open') AS sheet_status
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE p.deleted_at IS NULL
               AND SHA2(LOWER(TRIM(ps.consignor)), 256) = :customer_key
             ORDER BY p.collection_date DESC, p.id DESC, ps.line_number DESC
             LIMIT :limit",
        );
        $statement->bindValue(':customer_key', $customerKey);
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
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

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile
    {
        $this->ensureSchema();
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_customers
                (customer_key, display_name, contact_name, email, phone, address, city, country_code,
                 status, notes, next_follow_up_on, source, created_by, updated_by, created_at, updated_at)
             VALUES
                (:customer_key, :display_name, :contact_name, :email, :phone, :address, :city, :country_code,
                 :status, :notes, :next_follow_up_on, :source, :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name), contact_name = VALUES(contact_name), email = VALUES(email),
                phone = VALUES(phone), address = VALUES(address), city = VALUES(city), country_code = VALUES(country_code),
                status = VALUES(status), notes = VALUES(notes), next_follow_up_on = VALUES(next_follow_up_on),
                source = VALUES(source), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
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
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $this->find($customer->customerKey) ?? $customer;
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
                       metrics.first_shipment_on, metrics.last_shipment_on
                FROM pickup_customers c
                LEFT JOIN (
                    SELECT SHA2(LOWER(TRIM(ps.consignor)), 256) AS customer_key,
                           COUNT(*) AS shipment_count,
                           COALESCE(SUM(ps.amount_xaf), 0) AS total_cash_xaf,
                           MIN(p.collection_date) AS first_shipment_on,
                           MAX(p.collection_date) AS last_shipment_on
                    FROM pickup_shipments ps
                    INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
                    WHERE p.deleted_at IS NULL
                    GROUP BY SHA2(LOWER(TRIM(ps.consignor)), 256)
                ) metrics ON metrics.customer_key = c.customer_key";
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
                country_code CHAR(2) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                next_follow_up_on DATE NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
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
        $this->schemaReady = true;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
