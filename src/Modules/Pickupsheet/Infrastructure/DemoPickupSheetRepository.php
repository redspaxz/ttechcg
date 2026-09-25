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

    public function markPaid(string $referenceNumber, string $receiptNumber, string $actorId): PickupSheet
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
                $receiptNumber,
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

    public function recent(int $limit, int $offset = 0, string $search = ''): array
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        $sheets = array_reverse(is_array($sheets) ? $sheets : []);
        if ($search !== '') {
            $sheets = array_values(array_filter(
                $sheets,
                fn (mixed $sheet): bool => $sheet instanceof PickupSheet && $this->matchesSearch($sheet, $search),
            ));
        }

        return array_slice($sheets, $offset, $limit);
    }

    public function count(string $search = ''): int
    {
        $sheets = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($sheets)) {
            return 0;
        }

        if ($search === '') {
            return count($sheets);
        }

        return count(array_filter(
            $sheets,
            fn (mixed $sheet): bool => $sheet instanceof PickupSheet && $this->matchesSearch($sheet, $search),
        ));
    }

    public function unpaidBalance(string $search = ''): int
    {
        return array_sum(array_map(
            static fn (PickupSheet $sheet): int => $sheet->isPaid() ? 0 : $sheet->totalCashReceivedXaf,
            $this->recent(PHP_INT_MAX, 0, $search),
        ));
    }

    private function matchesSearch(PickupSheet $sheet, string $search): bool
    {
        $values = [
            $sheet->referenceNumber,
            $sheet->agentName,
            $sheet->collectionDate,
            $sheet->status,
            $sheet->paymentReceiptNumber ?? '',
        ];

        foreach ($sheet->shipments as $shipment) {
            $values[] = $shipment->consignor;
            $values[] = $shipment->awbNumber;
            $values[] = $shipment->destination;
            $values[] = $shipment->checkedBy;
        }

        return str_contains(strtolower(implode(' ', $values)), strtolower(trim($search)));
    }

    public function summary(): array
    {
        $sheets = $this->recent(PHP_INT_MAX);
        $minimumDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-3 months')->format('Y-m-d H:i:s');
        $recentSheets = array_filter($sheets, static fn (PickupSheet $sheet): bool => $sheet->createdAt >= $minimumDate);
        return [
            'sheetCount' => count($sheets),
            'unpaidSheetCount' => count(array_filter($sheets, static fn (PickupSheet $sheet): bool => !$sheet->isPaid())),
            'shipmentCount' => array_sum(array_map(static fn (PickupSheet $sheet): int => $sheet->shipmentCount(), $sheets)),
            'totalCashXaf' => array_sum(array_map(static fn (PickupSheet $sheet): int => $sheet->totalCashReceivedXaf, $recentSheets)),
            'unpaidBalanceXaf' => array_sum(array_map(
                static fn (PickupSheet $sheet): int => $sheet->isPaid() ? 0 : $sheet->totalCashReceivedXaf,
                $recentSheets,
            )),
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

    public function marketAnalysis(int $comparisonDays, int $trendMonths, int $destinationLimit): array
    {
        $today = new DateTimeImmutable('today');
        $currentStart = $today->modify('-' . ($comparisonDays - 1) . ' days')->format('Y-m-d');
        $previousEnd = $today->modify('-' . $comparisonDays . ' days')->format('Y-m-d');
        $previousStart = $today->modify('-' . (($comparisonDays * 2) - 1) . ' days')->format('Y-m-d');
        $trendStartDate = $today->modify('first day of -' . ($trendMonths - 1) . ' months');
        $trendStart = $trendStartDate->format('Y-m-d');
        $todayString = $today->format('Y-m-d');
        $periodTemplate = [
            'sheetCount' => 0,
            'shipmentCount' => 0,
            'totalCashXaf' => 0,
            'totalWeightKg' => 0.0,
            'totalPieces' => 0,
            'paidSheetCount' => 0,
            'uniqueSenders' => 0,
            'senderKeys' => [],
        ];
        $periods = ['current' => $periodTemplate, 'previous' => $periodTemplate];
        $monthly = [];
        for ($monthIndex = 0; $monthIndex < $trendMonths; $monthIndex++) {
            $month = $trendStartDate->modify('+' . $monthIndex . ' months')->format('Y-m');
            $monthly[$month] = [
                'month' => $month,
                'shipmentCount' => 0,
                'totalCashXaf' => 0,
                'totalWeightKg' => 0.0,
                'uniqueSenders' => 0,
                'senderKeys' => [],
            ];
        }
        $destinations = [];
        $trendSenderCounts = [];

        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            if (!$sheet instanceof PickupSheet || $sheet->collectionDate > $todayString) {
                continue;
            }

            $periodKey = match (true) {
                $sheet->collectionDate >= $currentStart => 'current',
                $sheet->collectionDate >= $previousStart && $sheet->collectionDate <= $previousEnd => 'previous',
                default => null,
            };
            if ($periodKey !== null) {
                $periods[$periodKey]['sheetCount']++;
                $periods[$periodKey]['paidSheetCount'] += $sheet->isPaid() ? 1 : 0;
            }

            $inTrend = $sheet->collectionDate >= $trendStart;
            $monthKey = substr($sheet->collectionDate, 0, 7);
            foreach ($sheet->shipments as $shipment) {
                $senderKey = strtolower(trim($shipment->consignor));
                if ($periodKey !== null) {
                    $periods[$periodKey]['shipmentCount']++;
                    $periods[$periodKey]['totalCashXaf'] += $shipment->amountXaf;
                    $periods[$periodKey]['totalWeightKg'] += (float) $shipment->weightKg;
                    $periods[$periodKey]['totalPieces'] += $shipment->pieces;
                    $periods[$periodKey]['senderKeys'][$senderKey] = true;
                }
                if (!$inTrend || !isset($monthly[$monthKey])) {
                    continue;
                }

                $monthly[$monthKey]['shipmentCount']++;
                $monthly[$monthKey]['totalCashXaf'] += $shipment->amountXaf;
                $monthly[$monthKey]['totalWeightKg'] += (float) $shipment->weightKg;
                $monthly[$monthKey]['senderKeys'][$senderKey] = true;
                $trendSenderCounts[$senderKey] = ($trendSenderCounts[$senderKey] ?? 0) + 1;
                $destination = strtoupper(trim($shipment->destination));
                $destinations[$destination] ??= [
                    'destination' => $destination,
                    'shipmentCount' => 0,
                    'totalCashXaf' => 0,
                    'totalWeightKg' => 0.0,
                ];
                $destinations[$destination]['shipmentCount']++;
                $destinations[$destination]['totalCashXaf'] += $shipment->amountXaf;
                $destinations[$destination]['totalWeightKg'] += (float) $shipment->weightKg;
            }
        }

        foreach (['current', 'previous'] as $periodKey) {
            $periods[$periodKey]['uniqueSenders'] = count($periods[$periodKey]['senderKeys']);
            $periods[$periodKey]['totalWeightKg'] = round((float) $periods[$periodKey]['totalWeightKg'], 3);
            unset($periods[$periodKey]['senderKeys']);
        }
        foreach ($monthly as &$month) {
            $month['uniqueSenders'] = count($month['senderKeys']);
            $month['totalWeightKg'] = round((float) $month['totalWeightKg'], 3);
            unset($month['senderKeys']);
        }
        unset($month);
        usort($destinations, static function (array $left, array $right): int {
            return ($right['shipmentCount'] <=> $left['shipmentCount'])
                ?: ($right['totalCashXaf'] <=> $left['totalCashXaf'])
                ?: strcmp($left['destination'], $right['destination']);
        });

        return [
            'comparisonDays' => $comparisonDays,
            'trendMonths' => $trendMonths,
            'current' => $periods['current'],
            'previous' => $periods['previous'],
            'monthly' => array_values($monthly),
            'destinations' => array_slice($destinations, 0, $destinationLimit),
            'repeatSenderCount' => count(array_filter($trendSenderCounts, static fn (int $count): bool => $count > 1)),
            'trendUniqueSenders' => count($trendSenderCounts),
        ];
    }

    public function consignorSuggestions(string $query, int $limit): array
    {
        $senders = [];
        $normalizedQuery = strtolower(trim($query));
        foreach ($this->recent(PHP_INT_MAX) as $sheet) {
            foreach ($sheet->shipments as $shipment) {
                $sender = trim($shipment->consignor);
                if ($sender === '' || ($normalizedQuery !== '' && !str_starts_with(strtolower($sender), $normalizedQuery))) {
                    continue;
                }
                $key = strtolower($sender);
                $senders[$key] ??= ['name' => $sender, 'frequency' => 0, 'latest' => '0000-00-00'];
                $senders[$key]['frequency']++;
                if ($sheet->collectionDate > $senders[$key]['latest']) {
                    $senders[$key]['latest'] = $sheet->collectionDate;
                }
            }
        }
        $rankedSenders = array_values($senders);
        $today = new DateTimeImmutable('today');
        $score = static function (array $sender) use ($normalizedQuery, $today): int {
            $exactMatch = $normalizedQuery !== '' && strtolower($sender['name']) === $normalizedQuery ? 1000000 : 0;
            $frequency = min((int) $sender['frequency'], 9999) * 100;
            $latest = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $sender['latest']);
            $ageDays = $latest instanceof DateTimeImmutable
                ? max(0, (int) $today->diff($latest)->format('%r%a') * -1)
                : 365;
            $recency = max(0, 365 - min($ageDays, 365));
            return $exactMatch + $frequency + $recency;
        };
        usort($rankedSenders, static function (array $left, array $right) use ($normalizedQuery, $score): int {
            if ($normalizedQuery !== '') {
                $scoreOrder = $score($right) <=> $score($left);
                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }
            }
            return strcasecmp($left['name'], $right['name']) ?: strcmp($left['name'], $right['name']);
        });
        $names = array_map(static fn (array $sender): string => $sender['name'], $rankedSenders);
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
