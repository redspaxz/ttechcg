<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
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
                    (agent_name, collection_date, shipment_count, total_cash_received_xaf, currency, privacy_consent_at, privacy_notice_version, created_at)
                 VALUES
                    (:agent_name, :collection_date, :shipment_count, :total_cash_received_xaf, :currency, :privacy_consent_at, :privacy_notice_version, :created_at)',
            );
            $sheetStatement->execute([
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

    private function utcDateTime(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
