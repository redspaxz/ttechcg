<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use App\Modules\Pickupsheet\Domain\PickupShipment;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class MysqlPickupSheetRepository implements PickupSheetRepository
{
    private bool $lifecycleSchemaReady = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(PickupSheet $pickupSheet): PickupSheet
    {
        $this->connection->beginTransaction();

        try {
            $sheetStatement = $this->connection->prepare(
                'INSERT INTO pickup_sheets
                    (reference_number, agent_name, collection_date, shipment_count, total_cash_received_xaf, currency, privacy_consent_at, privacy_notice_version, created_at)
                 VALUES
                    (:reference_number, :agent_name, :collection_date, :shipment_count, :total_cash_received_xaf, :currency, :privacy_consent_at, :privacy_notice_version, :created_at)',
            );
            $sheetStatement->execute([
                'reference_number' => $pickupSheet->referenceNumber,
                'agent_name' => $pickupSheet->agentName,
                'collection_date' => $pickupSheet->collectionDate,
                'shipment_count' => $pickupSheet->shipmentCount(),
                'total_cash_received_xaf' => $pickupSheet->totalCashReceivedXaf,
                'currency' => 'XAF',
                'privacy_consent_at' => $this->utcDateTime($pickupSheet->privacyConsentAt),
                'privacy_notice_version' => $pickupSheet->privacyNoticeVersion,
                'created_at' => $this->utcDateTime($pickupSheet->createdAt),
            ]);

            $pickupSheetId = (int) $this->connection->lastInsertId();
            $shipmentStatement = $this->connection->prepare(
                'INSERT INTO pickup_shipments
                    (pickup_sheet_id, line_number, consignor, awb_number, destination, amount_xaf, pieces, weight_kg, collection_time, checked_by, created_at)
                 VALUES
                    (:pickup_sheet_id, :line_number, :consignor, :awb_number, :destination, :amount_xaf, :pieces, :weight_kg, :collection_time, :checked_by, :created_at)',
            );

            foreach ($pickupSheet->shipments as $shipment) {
                $shipmentStatement->execute([
                    'pickup_sheet_id' => $pickupSheetId,
                    'line_number' => $shipment->lineNumber,
                    'consignor' => $shipment->consignor,
                    'awb_number' => $shipment->awbNumber,
                    'destination' => $shipment->destination,
                    'amount_xaf' => $shipment->amountXaf,
                    'pieces' => $shipment->pieces,
                    'weight_kg' => $shipment->weightKg,
                    'collection_time' => $shipment->collectionTime . ':00',
                    'checked_by' => $shipment->checkedBy,
                    'created_at' => $this->utcDateTime($pickupSheet->createdAt),
                ]);
            }

            $this->connection->commit();

            return new PickupSheet(
                $pickupSheetId,
                $pickupSheet->referenceNumber,
                $pickupSheet->agentName,
                $pickupSheet->collectionDate,
                $pickupSheet->shipments,
                $pickupSheet->totalCashReceivedXaf,
                $pickupSheet->privacyConsentAt,
                $pickupSheet->privacyNoticeVersion,
                $pickupSheet->createdAt,
                $pickupSheet->status,
                $pickupSheet->paidAt,
            );
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function update(PickupSheet $pickupSheet, string $actorId): PickupSheet
    {
        $this->ensureLifecycleSchema();
        $this->ensureAuditSchema();
        $this->connection->beginTransaction();

        try {
            $lockStatement = $this->connection->prepare(
                'SELECT id FROM pickup_sheets WHERE reference_number = :reference_number LIMIT 1 FOR UPDATE',
            );
            $lockStatement->execute(['reference_number' => $pickupSheet->referenceNumber]);
            $pickupSheetId = $lockStatement->fetchColumn();
            if ($pickupSheetId === false) {
                throw new \RuntimeException('Pickup sheet not found for update.');
            }

            $original = $this->findByReference($pickupSheet->referenceNumber);
            if ($original === null) {
                throw new \RuntimeException('Pickup sheet could not be loaded for audit.');
            }

            $sheetStatement = $this->connection->prepare(
                'UPDATE pickup_sheets
                 SET agent_name = :agent_name,
                     collection_date = :collection_date,
                     shipment_count = :shipment_count,
                     total_cash_received_xaf = :total_cash_received_xaf
                 WHERE id = :id',
            );
            $sheetStatement->execute([
                'id' => (int) $pickupSheetId,
                'agent_name' => $pickupSheet->agentName,
                'collection_date' => $pickupSheet->collectionDate,
                'shipment_count' => $pickupSheet->shipmentCount(),
                'total_cash_received_xaf' => $pickupSheet->totalCashReceivedXaf,
            ]);

            $deleteStatement = $this->connection->prepare('DELETE FROM pickup_shipments WHERE pickup_sheet_id = :pickup_sheet_id');
            $deleteStatement->execute(['pickup_sheet_id' => (int) $pickupSheetId]);

            $shipmentStatement = $this->connection->prepare(
                'INSERT INTO pickup_shipments
                    (pickup_sheet_id, line_number, consignor, awb_number, destination, amount_xaf, pieces, weight_kg, collection_time, checked_by, created_at)
                 VALUES
                    (:pickup_sheet_id, :line_number, :consignor, :awb_number, :destination, :amount_xaf, :pieces, :weight_kg, :collection_time, :checked_by, UTC_TIMESTAMP())',
            );
            foreach ($pickupSheet->shipments as $shipment) {
                $shipmentStatement->execute([
                    'pickup_sheet_id' => (int) $pickupSheetId,
                    'line_number' => $shipment->lineNumber,
                    'consignor' => $shipment->consignor,
                    'awb_number' => $shipment->awbNumber,
                    'destination' => $shipment->destination,
                    'amount_xaf' => $shipment->amountXaf,
                    'pieces' => $shipment->pieces,
                    'weight_kg' => $shipment->weightKg,
                    'collection_time' => $shipment->collectionTime . ':00',
                    'checked_by' => $shipment->checkedBy,
                ]);
            }

            $auditStatement = $this->connection->prepare(
                'INSERT INTO pickup_sheet_edit_audit
                    (pickup_sheet_id, reference_number, actor_id, before_snapshot, after_snapshot, created_at)
                 VALUES
                    (:pickup_sheet_id, :reference_number, :actor_id, :before_snapshot, :after_snapshot, UTC_TIMESTAMP())',
            );
            $auditStatement->execute([
                'pickup_sheet_id' => (int) $pickupSheetId,
                'reference_number' => $pickupSheet->referenceNumber,
                'actor_id' => $actorId,
                'before_snapshot' => $this->snapshot($original),
                'after_snapshot' => $this->snapshot($pickupSheet),
            ]);

            $this->connection->commit();

            return new PickupSheet(
                (int) $pickupSheetId,
                $pickupSheet->referenceNumber,
                $pickupSheet->agentName,
                $pickupSheet->collectionDate,
                $pickupSheet->shipments,
                $pickupSheet->totalCashReceivedXaf,
                $pickupSheet->privacyConsentAt,
                $pickupSheet->privacyNoticeVersion,
                $pickupSheet->createdAt,
                $pickupSheet->status,
                $pickupSheet->paidAt,
            );
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function markPaid(string $referenceNumber, string $actorId): PickupSheet
    {
        $this->ensureLifecycleSchema();
        $this->connection->beginTransaction();

        try {
            $lockStatement = $this->connection->prepare(
                'SELECT id FROM pickup_sheets
                 WHERE reference_number = :reference_number AND deleted_at IS NULL
                 LIMIT 1 FOR UPDATE',
            );
            $lockStatement->execute(['reference_number' => $referenceNumber]);
            $pickupSheetId = $lockStatement->fetchColumn();
            if ($pickupSheetId === false) {
                throw new \RuntimeException('Pickup sheet not found for payment status update.');
            }

            $original = $this->findByReference($referenceNumber);
            if ($original === null) {
                throw new \RuntimeException('Pickup sheet could not be loaded for payment audit.');
            }

            $statement = $this->connection->prepare(
                "UPDATE pickup_sheets
                 SET status = 'paid', paid_at = UTC_TIMESTAMP(), paid_by = :actor_id
                 WHERE id = :id AND status <> 'paid'",
            );
            $statement->execute(['id' => (int) $pickupSheetId, 'actor_id' => $actorId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Pickup sheet is already marked paid.');
            }

            $paid = new PickupSheet(
                $original->id,
                $original->referenceNumber,
                $original->agentName,
                $original->collectionDate,
                $original->shipments,
                $original->totalCashReceivedXaf,
                $original->privacyConsentAt,
                $original->privacyNoticeVersion,
                $original->createdAt,
                'paid',
                gmdate(DATE_ATOM),
            );
            $this->writeLifecycleAudit((int) $pickupSheetId, $referenceNumber, $actorId, 'paid', $this->snapshot($original), $this->snapshot($paid));
            $this->connection->commit();
            return $paid;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(string $referenceNumber, string $actorId): void
    {
        $this->ensureLifecycleSchema();
        $this->connection->beginTransaction();

        try {
            $lockStatement = $this->connection->prepare(
                'SELECT id FROM pickup_sheets
                 WHERE reference_number = :reference_number AND deleted_at IS NULL
                 LIMIT 1 FOR UPDATE',
            );
            $lockStatement->execute(['reference_number' => $referenceNumber]);
            $pickupSheetId = $lockStatement->fetchColumn();
            if ($pickupSheetId === false) {
                throw new \RuntimeException('Pickup sheet not found for deletion.');
            }

            $original = $this->findByReference($referenceNumber);
            if ($original === null) {
                throw new \RuntimeException('Pickup sheet could not be loaded for deletion audit.');
            }

            $statement = $this->connection->prepare(
                'UPDATE pickup_sheets
                 SET deleted_at = UTC_TIMESTAMP(), deleted_by = :actor_id
                 WHERE id = :id AND deleted_at IS NULL',
            );
            $statement->execute(['id' => (int) $pickupSheetId, 'actor_id' => $actorId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Pickup sheet is already deleted.');
            }

            $this->writeLifecycleAudit((int) $pickupSheetId, $referenceNumber, $actorId, 'delete', $this->snapshot($original), null);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function recent(int $limit, int $offset = 0, string $search = ''): array
    {
        $this->ensureLifecycleSchema();
        [$searchSql, $searchParameters] = $this->searchCondition($search);
        $sheetStatement = $this->connection->prepare(
            'SELECT id, reference_number, agent_name, collection_date, total_cash_received_xaf,
                    privacy_consent_at, privacy_notice_version, created_at, status, paid_at
             FROM pickup_sheets p
             WHERE p.deleted_at IS NULL' . $searchSql . '
             ORDER BY collection_date DESC, id DESC
             LIMIT :limit OFFSET :offset',
        );
        foreach ($searchParameters as $name => $value) {
            $sheetStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $sheetStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $sheetStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $sheetStatement->execute();
        $sheetRows = $sheetStatement->fetchAll();

        if ($sheetRows === []) {
            return [];
        }

        $sheetIds = array_map(static fn (array $row): int => (int) $row['id'], $sheetRows);
        $placeholders = implode(',', array_fill(0, count($sheetIds), '?'));
        $shipmentStatement = $this->connection->prepare(
            'SELECT pickup_sheet_id, line_number, consignor, awb_number, destination,
                    amount_xaf, pieces, weight_kg, collection_time, checked_by
             FROM pickup_shipments
             WHERE pickup_sheet_id IN (' . $placeholders . ')
             ORDER BY pickup_sheet_id, line_number',
        );
        $shipmentStatement->execute($sheetIds);

        $shipmentsBySheet = [];
        foreach ($shipmentStatement->fetchAll() as $row) {
            $sheetId = (int) $row['pickup_sheet_id'];
            $shipmentsBySheet[$sheetId][] = new PickupShipment(
                (int) $row['line_number'],
                (string) $row['consignor'],
                (string) $row['awb_number'],
                (string) $row['destination'],
                (int) $row['amount_xaf'],
                (int) $row['pieces'],
                (string) $row['weight_kg'],
                substr((string) $row['collection_time'], 0, 5),
                (string) $row['checked_by'],
            );
        }

        $pickupSheets = [];
        foreach ($sheetRows as $row) {
            $sheetId = (int) $row['id'];
            $pickupSheets[] = new PickupSheet(
                $sheetId,
                (string) $row['reference_number'],
                (string) $row['agent_name'],
                (string) $row['collection_date'],
                $shipmentsBySheet[$sheetId] ?? [],
                (int) $row['total_cash_received_xaf'],
                (string) $row['privacy_consent_at'],
                (string) $row['privacy_notice_version'],
                (string) $row['created_at'],
                (string) ($row['status'] ?? 'open'),
                isset($row['paid_at']) ? (string) $row['paid_at'] : null,
            );
        }

        return $pickupSheets;
    }

    public function count(string $search = ''): int
    {
        $this->ensureLifecycleSchema();
        [$searchSql, $searchParameters] = $this->searchCondition($search);
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM pickup_sheets p WHERE p.deleted_at IS NULL' . $searchSql,
        );
        $statement->execute($searchParameters);
        return (int) $statement->fetchColumn();
    }

    public function unpaidBalance(string $search = ''): int
    {
        $this->ensureLifecycleSchema();
        [$searchSql, $searchParameters] = $this->searchCondition($search);
        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(p.total_cash_received_xaf), 0)
             FROM pickup_sheets p
             WHERE p.deleted_at IS NULL AND p.status = 'open'" . $searchSql,
        );
        $statement->execute($searchParameters);
        return (int) $statement->fetchColumn();
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function searchCondition(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }

        $value = strtolower($search);
        return [
            ' AND (
                INSTR(LOWER(p.reference_number), :search_reference) > 0
                OR INSTR(LOWER(p.agent_name), :search_agent) > 0
                OR INSTR(LOWER(CAST(p.collection_date AS CHAR)), :search_date) > 0
                OR INSTR(LOWER(p.status), :search_status) > 0
                OR EXISTS (
                    SELECT 1
                    FROM pickup_shipments searched_shipment
                    WHERE searched_shipment.pickup_sheet_id = p.id
                      AND (
                        INSTR(LOWER(searched_shipment.consignor), :search_consignor) > 0
                        OR INSTR(LOWER(searched_shipment.awb_number), :search_awb) > 0
                        OR INSTR(LOWER(searched_shipment.destination), :search_destination) > 0
                        OR INSTR(LOWER(searched_shipment.checked_by), :search_checker) > 0
                      )
                )
             )',
            [
                'search_reference' => $value,
                'search_agent' => $value,
                'search_date' => $value,
                'search_status' => $value,
                'search_consignor' => $value,
                'search_awb' => $value,
                'search_destination' => $value,
                'search_checker' => $value,
            ],
        ];
    }

    public function summary(): array
    {
        $this->ensureLifecycleSchema();
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) AS sheet_count,
                    COALESCE(SUM(shipment_count), 0) AS shipment_count,
                    COALESCE(SUM(total_cash_received_xaf), 0) AS total_cash_xaf,
                    COALESCE(SUM(CASE WHEN status = \'open\' THEN total_cash_received_xaf ELSE 0 END), 0) AS unpaid_balance_xaf,
                    MAX(created_at) AS latest_created_at
             FROM pickup_sheets
             WHERE deleted_at IS NULL',
        );
        $statement->execute();
        $row = $statement->fetch();

        return [
            'sheetCount' => (int) ($row['sheet_count'] ?? 0),
            'shipmentCount' => (int) ($row['shipment_count'] ?? 0),
            'totalCashXaf' => (int) ($row['total_cash_xaf'] ?? 0),
            'unpaidBalanceXaf' => (int) ($row['unpaid_balance_xaf'] ?? 0),
            'latestCreatedAt' => isset($row['latest_created_at']) ? (string) $row['latest_created_at'] : null,
        ];
    }

    public function activityByDay(int $days): array
    {
        $this->ensureLifecycleSchema();
        $days = max(1, min($days, 31));
        $statement = $this->connection->prepare(
            'SELECT DATE(created_at) AS activity_date,
                    COUNT(*) AS sheet_count,
                    COALESCE(SUM(shipment_count), 0) AS shipment_count,
                    COALESCE(SUM(total_cash_received_xaf), 0) AS total_cash_xaf
             FROM pickup_sheets
             WHERE deleted_at IS NULL
               AND created_at >= UTC_DATE() - INTERVAL ' . ($days - 1) . ' DAY
             GROUP BY DATE(created_at)
             ORDER BY activity_date',
        );
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'date' => (string) $row['activity_date'],
            'sheetCount' => (int) $row['sheet_count'],
            'shipmentCount' => (int) $row['shipment_count'],
            'totalCashXaf' => (int) $row['total_cash_xaf'],
        ], $statement->fetchAll());
    }

    public function topDestinations(int $limit): array
    {
        $this->ensureLifecycleSchema();
        $statement = $this->connection->prepare(
            'SELECT ps.destination AS destination,
                    COUNT(*) AS shipment_count,
                    COALESCE(SUM(ps.amount_xaf), 0) AS total_cash_xaf
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE p.deleted_at IS NULL
             GROUP BY ps.destination
             ORDER BY shipment_count DESC, total_cash_xaf DESC
             LIMIT :limit',
        );
        $statement->bindValue(':limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'destination' => (string) $row['destination'],
            'shipmentCount' => (int) $row['shipment_count'],
            'totalCashXaf' => (int) $row['total_cash_xaf'],
        ], $statement->fetchAll());
    }

    public function topSenders(int $months, int $limit): array
    {
        $this->ensureLifecycleSchema();
        $today = new \DateTimeImmutable('today');
        $minimumDate = $today->modify('-' . max(1, $months) . ' months')->format('Y-m-d');
        $maximumDate = $today->format('Y-m-d');
        $statement = $this->connection->prepare(
            'SELECT MIN(TRIM(ps.consignor)) AS sender,
                    COUNT(*) AS shipment_count
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE p.deleted_at IS NULL
               AND p.collection_date >= :minimum_date
               AND p.collection_date <= :maximum_date
             GROUP BY LOWER(TRIM(ps.consignor))
             ORDER BY shipment_count DESC, sender ASC
             LIMIT :limit',
        );
        $statement->bindValue(':minimum_date', $minimumDate);
        $statement->bindValue(':maximum_date', $maximumDate);
        $statement->bindValue(':limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'sender' => (string) $row['sender'],
            'shipmentCount' => (int) $row['shipment_count'],
        ], $statement->fetchAll());
    }

    public function consignorSuggestions(string $query, int $limit): array
    {
        $this->ensureLifecycleSchema();
        $statement = $this->connection->prepare(
            'SELECT MIN(TRIM(ps.consignor)) AS consignor
             FROM pickup_shipments ps
             INNER JOIN pickup_sheets p ON p.id = ps.pickup_sheet_id
             WHERE p.deleted_at IS NULL AND TRIM(ps.consignor) <> \'\'
               AND (:query_empty = 1 OR LOCATE(:query_value, LOWER(TRIM(ps.consignor))) > 0)
             GROUP BY LOWER(TRIM(ps.consignor))
             ORDER BY LOWER(consignor) ASC, consignor ASC
             LIMIT :limit',
        );
        $normalizedQuery = strtolower(trim($query));
        $statement->bindValue(':query_empty', $normalizedQuery === '' ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':query_value', $normalizedQuery);
        $statement->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_filter(
            array_map(static fn (mixed $name): string => trim((string) $name), $statement->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $name): bool => $name !== '',
        ));
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        $this->ensureLifecycleSchema();
        $sheetStatement = $this->connection->prepare(
            'SELECT id, reference_number, agent_name, collection_date, total_cash_received_xaf,
                    privacy_consent_at, privacy_notice_version, created_at, status, paid_at
             FROM pickup_sheets
             WHERE reference_number = :reference_number AND deleted_at IS NULL
             LIMIT 1',
        );
        $sheetStatement->execute(['reference_number' => $referenceNumber]);
        $row = $sheetStatement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $sheetId = (int) $row['id'];
        $shipmentStatement = $this->connection->prepare(
            'SELECT line_number, consignor, awb_number, destination, amount_xaf,
                    pieces, weight_kg, collection_time, checked_by
             FROM pickup_shipments
             WHERE pickup_sheet_id = :pickup_sheet_id
             ORDER BY line_number',
        );
        $shipmentStatement->execute(['pickup_sheet_id' => $sheetId]);

        $shipments = [];
        foreach ($shipmentStatement->fetchAll() as $shipmentRow) {
            $shipments[] = new PickupShipment(
                (int) $shipmentRow['line_number'],
                (string) $shipmentRow['consignor'],
                (string) $shipmentRow['awb_number'],
                (string) $shipmentRow['destination'],
                (int) $shipmentRow['amount_xaf'],
                (int) $shipmentRow['pieces'],
                (string) $shipmentRow['weight_kg'],
                substr((string) $shipmentRow['collection_time'], 0, 5),
                (string) $shipmentRow['checked_by'],
            );
        }

        return new PickupSheet(
            $sheetId,
            (string) $row['reference_number'],
            (string) $row['agent_name'],
            (string) $row['collection_date'],
            $shipments,
            (int) $row['total_cash_received_xaf'],
            (string) $row['privacy_consent_at'],
            (string) $row['privacy_notice_version'],
            (string) $row['created_at'],
            (string) ($row['status'] ?? 'open'),
            isset($row['paid_at']) ? (string) $row['paid_at'] : null,
        );
    }

    private function utcDateTime(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function ensureAuditSchema(): void
    {
        try {
            $this->connection->query('SELECT 1 FROM pickup_sheet_edit_audit LIMIT 1');
            return;
        } catch (\PDOException) {
            // An authenticated administrator may initialize the audit table on first edit.
        }

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS pickup_sheet_edit_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pickup_sheet_id BIGINT UNSIGNED NOT NULL,
                reference_number VARCHAR(48) NOT NULL,
                actor_id CHAR(24) NOT NULL,
                before_snapshot LONGTEXT NOT NULL,
                after_snapshot LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX pickup_sheet_edit_audit_reference_idx (reference_number, created_at),
                CONSTRAINT pickup_sheet_edit_audit_sheet_fk
                    FOREIGN KEY (pickup_sheet_id) REFERENCES pickup_sheets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function ensureLifecycleSchema(): void
    {
        if ($this->lifecycleSchemaReady) {
            return;
        }

        try {
            $this->connection->query('SELECT status, paid_at, deleted_at FROM pickup_sheets LIMIT 1');
        } catch (\PDOException) {
            $this->connection->exec(
                "ALTER TABLE pickup_sheets
                    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'open' AFTER created_at,
                    ADD COLUMN paid_at DATETIME NULL AFTER status,
                    ADD COLUMN paid_by CHAR(24) NULL AFTER paid_at,
                    ADD COLUMN deleted_at DATETIME NULL AFTER paid_by,
                    ADD COLUMN deleted_by CHAR(24) NULL AFTER deleted_at,
                    ADD INDEX pickup_sheets_status_idx (status, deleted_at),
                    ADD INDEX pickup_sheets_deleted_at_idx (deleted_at)",
            );
        }

        $this->connection->exec(
            "CREATE TABLE IF NOT EXISTS pickup_sheet_lifecycle_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pickup_sheet_id BIGINT UNSIGNED NOT NULL,
                reference_number VARCHAR(48) NOT NULL,
                actor_id CHAR(24) NOT NULL,
                action VARCHAR(20) NOT NULL,
                before_snapshot LONGTEXT NOT NULL,
                after_snapshot LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX pickup_sheet_lifecycle_audit_reference_idx (reference_number, created_at),
                INDEX pickup_sheet_lifecycle_audit_action_idx (action, created_at),
                CONSTRAINT pickup_sheet_lifecycle_audit_sheet_fk
                    FOREIGN KEY (pickup_sheet_id) REFERENCES pickup_sheets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
        $this->lifecycleSchemaReady = true;
    }

    private function writeLifecycleAudit(
        int $pickupSheetId,
        string $referenceNumber,
        string $actorId,
        string $action,
        string $beforeSnapshot,
        ?string $afterSnapshot,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pickup_sheet_lifecycle_audit
                (pickup_sheet_id, reference_number, actor_id, action, before_snapshot, after_snapshot, created_at)
             VALUES
                (:pickup_sheet_id, :reference_number, :actor_id, :action, :before_snapshot, :after_snapshot, UTC_TIMESTAMP())',
        );
        $statement->execute([
            'pickup_sheet_id' => $pickupSheetId,
            'reference_number' => $referenceNumber,
            'actor_id' => $actorId,
            'action' => $action,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => $afterSnapshot,
        ]);
    }

    private function snapshot(PickupSheet $pickupSheet): string
    {
        return (string) json_encode([
            'reference_number' => $pickupSheet->referenceNumber,
            'agent_name' => $pickupSheet->agentName,
            'collection_date' => $pickupSheet->collectionDate,
            'total_cash_received_xaf' => $pickupSheet->totalCashReceivedXaf,
            'status' => $pickupSheet->status,
            'paid_at' => $pickupSheet->paidAt,
            'shipments' => array_map(static fn (PickupShipment $shipment): array => [
                'line_number' => $shipment->lineNumber,
                'consignor' => $shipment->consignor,
                'awb_number' => $shipment->awbNumber,
                'destination' => $shipment->destination,
                'amount_xaf' => $shipment->amountXaf,
                'pieces' => $shipment->pieces,
                'weight_kg' => $shipment->weightKg,
                'collection_time' => $shipment->collectionTime,
                'checked_by' => $shipment->checkedBy,
            ], $pickupSheet->shipments),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
