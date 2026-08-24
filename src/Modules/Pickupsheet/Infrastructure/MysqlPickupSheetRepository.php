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
            );
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function recent(int $limit): array
    {
        $sheetStatement = $this->connection->prepare(
            'SELECT id, reference_number, agent_name, collection_date, total_cash_received_xaf,
                    privacy_consent_at, privacy_notice_version, created_at
             FROM pickup_sheets
             ORDER BY collection_date DESC, id DESC
             LIMIT :limit',
        );
        $sheetStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
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
            );
        }

        return $pickupSheets;
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        $sheetStatement = $this->connection->prepare(
            'SELECT id, reference_number, agent_name, collection_date, total_cash_received_xaf,
                    privacy_consent_at, privacy_notice_version, created_at
             FROM pickup_sheets
             WHERE reference_number = :reference_number
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
        );
    }

    private function utcDateTime(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
