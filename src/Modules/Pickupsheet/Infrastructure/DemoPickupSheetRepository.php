<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use DateTimeImmutable;

final class DemoPickupSheetRepository implements PickupSheetRepository
{
    private const SESSION_KEY = '_demo_pickup_sheets';

    public function create(PickupSheet $pickupSheet): PickupSheet
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        $created = new PickupSheet(
            count($sheets) + 1,
            $pickupSheet->referenceNumber,
            $pickupSheet->agentName,
            $pickupSheet->collectionDate,
            $pickupSheet->shipments,
            $pickupSheet->totalCashReceivedXaf,
            $pickupSheet->privacyConsentAt,
            $pickupSheet->privacyNoticeVersion,
            $pickupSheet->createdAt,
        );
        $sheets[] = $created;
        $_SESSION[self::SESSION_KEY] = $sheets;

        return $created;
    }

    public function update(PickupSheet $pickupSheet, string $actorId): PickupSheet
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        foreach (is_array($sheets) ? $sheets : [] as $index => $stored) {
            if ($stored instanceof PickupSheet && $stored->referenceNumber === $pickupSheet->referenceNumber) {
                $sheets[$index] = $pickupSheet;
                $_SESSION[self::SESSION_KEY] = $sheets;
                return $pickupSheet;
            }
        }

        throw new \RuntimeException('Pickup sheet not found for update.');
    }

    public function markPaid(string $referenceNumber, string $actorId): PickupSheet
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        foreach (is_array($sheets) ? $sheets : [] as $index => $stored) {
            if (!$stored instanceof PickupSheet || $stored->referenceNumber !== $referenceNumber) {
                continue;
            }

            $paid = new PickupSheet(
                $stored->id,
                $stored->referenceNumber,
                $stored->agentName,
                $stored->collectionDate,
                $stored->shipments,
                $stored->totalCashReceivedXaf,
                $stored->privacyConsentAt,
                $stored->privacyNoticeVersion,
                $stored->createdAt,
                'paid',
                gmdate(DATE_ATOM),
            );
            $sheets[$index] = $paid;
            $_SESSION[self::SESSION_KEY] = $sheets;
            return $paid;
        }

        throw new \RuntimeException('Pickup sheet not found for payment status update.');
    }

    public function delete(string $referenceNumber, string $actorId): void
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        foreach (is_array($sheets) ? $sheets : [] as $index => $stored) {
            if ($stored instanceof PickupSheet && $stored->referenceNumber === $referenceNumber) {
                unset($sheets[$index]);
                $_SESSION[self::SESSION_KEY] = array_values($sheets);
                return;
            }
        }

        throw new \RuntimeException('Pickup sheet not found for deletion.');
    }

    public function recent(int $limit, int $offset = 0): array
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        return array_slice(array_reverse(is_array($sheets) ? $sheets : []), $offset, $limit);
    }

    public function count(): int
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($sheets) ? count($sheets) : 0;
    }

    public function summary(): array
    {
        $sheets = $this->recent(PHP_INT_MAX);
        return [
            'sheetCount' => count($sheets),
            'shipmentCount' => array_sum(array_map(static fn (PickupSheet $sheet): int => $sheet->shipmentCount(), $sheets)),
            'totalCashXaf' => array_sum(array_map(static fn (PickupSheet $sheet): int => $sheet->totalCashReceivedXaf, $sheets)),
            'latestCreatedAt' => $sheets[0]->createdAt ?? null,
        ];
    }

    public function activityByDay(int $days): array
    {
        $minimumDate = gmdate('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
        $activity = [];
        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            $date = substr($sheet->createdAt, 0, 10);
            if ($date < $minimumDate) {
                continue;
            }
            $activity[$date] ??= ['date' => $date, 'sheetCount' => 0, 'shipmentCount' => 0, 'totalCashXaf' => 0];
            $activity[$date]['sheetCount']++;
            $activity[$date]['shipmentCount'] += $sheet->shipmentCount();
            $activity[$date]['totalCashXaf'] += $sheet->totalCashReceivedXaf;
        }
        ksort($activity);
        return array_values($activity);
    }

    public function topDestinations(int $limit): array
    {
        $destinations = [];
        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            foreach ($sheet->shipments as $shipment) {
                $destinations[$shipment->destination] ??= [
                    'destination' => $shipment->destination,
                    'shipmentCount' => 0,
                    'totalCashXaf' => 0,
                ];
                $destinations[$shipment->destination]['shipmentCount']++;
                $destinations[$shipment->destination]['totalCashXaf'] += $shipment->amountXaf;
            }
        }
        usort($destinations, static fn (array $left, array $right): int => $right['shipmentCount'] <=> $left['shipmentCount']);
        return array_slice($destinations, 0, max(1, $limit));
    }

    public function topSenders(int $months, int $limit): array
    {
        $today = new DateTimeImmutable('today');
        $minimumDate = $today->modify('-' . max(1, $months) . ' months')->format('Y-m-d');
        $maximumDate = $today->format('Y-m-d');
        $senders = [];

        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            if ($sheet->collectionDate < $minimumDate || $sheet->collectionDate > $maximumDate) {
                continue;
            }

            foreach ($sheet->shipments as $shipment) {
                $sender = trim($shipment->consignor);
                $senderKey = strtolower($sender);
                $senders[$senderKey] ??= ['sender' => $sender, 'shipmentCount' => 0];
                $senders[$senderKey]['shipmentCount']++;
            }
        }

        usort($senders, static function (array $left, array $right): int {
            $countOrder = $right['shipmentCount'] <=> $left['shipmentCount'];
            return $countOrder !== 0 ? $countOrder : strcasecmp($left['sender'], $right['sender']);
        });

        return array_slice($senders, 0, max(1, min($limit, 10)));
    }

    public function consignorSuggestions(int $limit): array
    {
        $senders = [];
        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            foreach ($sheet->shipments as $shipment) {
                $sender = trim($shipment->consignor);
                if ($sender === '') {
                    continue;
                }
                $key = strtolower($sender);
                $senders[$key] ??= $sender;
            }
        }
        $names = array_values($senders);
        usort($names, static fn (string $left, string $right): int => strcasecmp($left, $right));
        return array_slice($names, 0, max(1, min($limit, 50)));
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        foreach ($this->recent(PHP_INT_MAX) as $pickupSheet) {
            if ($pickupSheet instanceof PickupSheet && $pickupSheet->referenceNumber === $referenceNumber) {
                return $pickupSheet;
            }
        }

        return null;
    }
}
