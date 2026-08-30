<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use InvalidArgumentException;
use RuntimeException;

final class DemoCustomerRepository implements CustomerRepository
{
    private const SESSION_KEY = '_demo_pickup_customers';
    private const REWARDS_SESSION_KEY = '_demo_pickup_customer_rewards';

    public function __construct(private readonly PickupSheetRepository $pickupSheets)
    {
    }

    public function synchronizeFromShipments(): void
    {
        $profiles = $this->profiles();
        foreach ($this->metrics() as $key => $metric) {
            if (isset($profiles[$key])) {
                continue;
            }
            $profiles[$key] = [
                'id' => count($profiles) + 1,
                'customerKey' => $key,
                'displayName' => $metric['displayName'],
                'contactName' => '',
                'email' => '',
                'phone' => '',
                'address' => '',
                'city' => '',
                'countryCode' => '',
                'status' => 'active',
                'notes' => '',
                'nextFollowUpOn' => null,
                'source' => 'shipment',
                'createdAt' => gmdate('Y-m-d H:i:s'),
                'updatedAt' => gmdate('Y-m-d H:i:s'),
            ];
        }
        $_SESSION[self::SESSION_KEY] = $profiles;
    }

    public function paginated(string $search, string $status, int $limit, int $offset): array
    {
        $metrics = $this->metrics();
        $profiles = array_values(array_filter($this->profiles(), static function (array $profile) use ($search, $status): bool {
            if ($status !== '' && ($profile['status'] ?? '') !== $status) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $haystack = implode(' ', [
                $profile['displayName'] ?? '', $profile['contactName'] ?? '', $profile['email'] ?? '',
                $profile['phone'] ?? '', $profile['city'] ?? '',
            ]);
            return stripos($haystack, $search) !== false;
        }));
        usort($profiles, static function (array $left, array $right): int {
            $today = gmdate('Y-m-d');
            $leftDue = ($left['nextFollowUpOn'] ?? null) !== null && $left['nextFollowUpOn'] <= $today && ($left['status'] ?? '') !== 'inactive';
            $rightDue = ($right['nextFollowUpOn'] ?? null) !== null && $right['nextFollowUpOn'] <= $today && ($right['status'] ?? '') !== 'inactive';
            return ($rightDue <=> $leftDue)
                ?: (strcmp((string) ($left['displayName'] ?? ''), (string) ($right['displayName'] ?? '')));
        });

        return [
            'items' => array_map(
                fn (array $profile): CustomerProfile => $this->profile($profile, $metrics[$profile['customerKey']] ?? []),
                array_slice($profiles, max(0, $offset), max(1, $limit)),
            ),
            'totalRecords' => count($profiles),
        ];
    }

    public function summary(): array
    {
        $profiles = $this->profiles();
        $today = gmdate('Y-m-d');
        return [
            'customerCount' => count($profiles),
            'activeCount' => count(array_filter($profiles, static fn (array $profile): bool => ($profile['status'] ?? '') === 'active')),
            'attentionCount' => count(array_filter($profiles, static fn (array $profile): bool => ($profile['status'] ?? '') === 'attention')),
            'followUpsDue' => count(array_filter($profiles, static fn (array $profile): bool => ($profile['nextFollowUpOn'] ?? null) !== null
                && $profile['nextFollowUpOn'] <= $today
                && ($profile['status'] ?? '') !== 'inactive')),
        ];
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        $profile = $this->profiles()[$customerKey] ?? null;
        return is_array($profile) ? $this->profile($profile, $this->metrics()[$customerKey] ?? []) : null;
    }

    public function recentShipments(string $customerKey, int $limit): array
    {
        $shipments = [];
        foreach ($this->pickupSheets->recent(PHP_INT_MAX) as $sheet) {
            foreach ($sheet->shipments as $shipment) {
                if ($this->key($shipment->consignor) !== $customerKey) {
                    continue;
                }
                $shipments[] = [
                    'referenceNumber' => $sheet->referenceNumber,
                    'collectionDate' => $sheet->collectionDate,
                    'awbNumber' => $shipment->awbNumber,
                    'destination' => $shipment->destination,
                    'amountXaf' => $shipment->amountXaf,
                    'status' => $sheet->status,
                ];
            }
        }
        usort($shipments, static fn (array $left, array $right): int => strcmp($right['collectionDate'], $left['collectionDate']));
        return array_slice($shipments, 0, max(1, $limit));
    }

    public function save(CustomerProfile $customer, string $actorId): CustomerProfile
    {
        $profiles = $this->profiles();
        $existing = $profiles[$customer->customerKey] ?? null;
        $now = gmdate('Y-m-d H:i:s');
        $profiles[$customer->customerKey] = [
            'id' => is_array($existing) ? (int) $existing['id'] : count($profiles) + 1,
            'customerKey' => $customer->customerKey,
            'displayName' => $customer->displayName,
            'contactName' => $customer->contactName,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'city' => $customer->city,
            'countryCode' => $customer->countryCode,
            'status' => $customer->status,
            'notes' => $customer->notes,
            'nextFollowUpOn' => $customer->nextFollowUpOn,
            'source' => $customer->source,
            'createdAt' => is_array($existing) ? $existing['createdAt'] : $now,
            'updatedAt' => $now,
        ];
        $_SESSION[self::SESSION_KEY] = $profiles;
        return $this->find($customer->customerKey) ?? $customer;
    }

    public function rewardAdjustments(string $customerKey, int $limit): array
    {
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        $adjustments = is_array($adjustments) ? $adjustments : [];
        $customerAdjustments = array_values(array_filter(
            $adjustments,
            static fn (mixed $adjustment): bool => is_array($adjustment)
                && ($adjustment['customerKey'] ?? '') === $customerKey,
        ));

        return array_slice(array_reverse($customerAdjustments), 0, max(1, min($limit, 50)));
    }

    public function rewardRedemptions(string $customerKey, int $limit): array
    {
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        $adjustments = is_array($adjustments) ? $adjustments : [];
        $redemptions = array_values(array_filter(
            $adjustments,
            static fn (mixed $adjustment): bool => is_array($adjustment)
                && ($adjustment['customerKey'] ?? '') === $customerKey
                && (int) ($adjustment['pointsDelta'] ?? 0) < 0,
        ));

        return array_slice(array_reverse($redemptions), 0, max(1, min($limit, 50)));
    }

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
    ): CustomerProfile
    {
        $customer = $this->find($customerKey);
        if ($customer === null) {
            throw new RuntimeException('Customer profile not found for reward adjustment.');
        }
        if ($customer->rewardBalance() + $pointsDelta < 0) {
            throw new InvalidArgumentException('A redemption cannot exceed the available reward balance.');
        }

        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        $adjustments = is_array($adjustments) ? $adjustments : [];
        $adjustments[] = [
            'customerKey' => $customerKey,
            'pointsDelta' => $pointsDelta,
            'reason' => $reason,
            'actorId' => $actorId,
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ];
        $_SESSION[self::REWARDS_SESSION_KEY] = $adjustments;

        return $this->find($customerKey) ?? throw new RuntimeException('Updated customer rewards could not be loaded.');
    }

    /** @return array<string, array<string, mixed>> */
    private function profiles(): array
    {
        $profiles = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($profiles) ? $profiles : [];
    }

    /** @return array<string, array{displayName: string, shipmentCount: int, totalCashXaf: int, totalWeightGrams: int, firstShipmentOn: ?string, lastShipmentOn: ?string}> */
    private function metrics(): array
    {
        $metrics = [];
        foreach ($this->pickupSheets->recent(PHP_INT_MAX) as $sheet) {
            if (!$sheet instanceof PickupSheet) {
                continue;
            }
            foreach ($sheet->shipments as $shipment) {
                $key = $this->key($shipment->consignor);
                $metrics[$key] ??= [
                    'displayName' => trim($shipment->consignor),
                    'shipmentCount' => 0,
                    'totalCashXaf' => 0,
                    'totalWeightGrams' => 0,
                    'firstShipmentOn' => null,
                    'lastShipmentOn' => null,
                ];
                $metrics[$key]['shipmentCount']++;
                $metrics[$key]['totalCashXaf'] += $shipment->amountXaf;
                $metrics[$key]['totalWeightGrams'] += $this->weightToGrams($shipment->weightKg);
                $metrics[$key]['firstShipmentOn'] = $metrics[$key]['firstShipmentOn'] === null
                    ? $sheet->collectionDate
                    : min($metrics[$key]['firstShipmentOn'], $sheet->collectionDate);
                $metrics[$key]['lastShipmentOn'] = $metrics[$key]['lastShipmentOn'] === null
                    ? $sheet->collectionDate
                    : max($metrics[$key]['lastShipmentOn'], $sheet->collectionDate);
            }
        }
        return $metrics;
    }

    /** @param array<string, mixed> $profile @param array<string, mixed> $metrics */
    private function profile(array $profile, array $metrics): CustomerProfile
    {
        return new CustomerProfile(
            (int) ($profile['id'] ?? 0),
            (string) $profile['customerKey'],
            (string) $profile['displayName'],
            (string) ($profile['contactName'] ?? ''),
            (string) ($profile['email'] ?? ''),
            (string) ($profile['phone'] ?? ''),
            (string) ($profile['address'] ?? ''),
            (string) ($profile['city'] ?? ''),
            (string) ($profile['countryCode'] ?? ''),
            (string) ($profile['status'] ?? 'active'),
            (string) ($profile['notes'] ?? ''),
            isset($profile['nextFollowUpOn']) ? (string) $profile['nextFollowUpOn'] : null,
            (string) ($profile['source'] ?? 'manual'),
            (int) ($metrics['shipmentCount'] ?? 0),
            (int) ($metrics['totalCashXaf'] ?? 0),
            isset($metrics['firstShipmentOn']) ? (string) $metrics['firstShipmentOn'] : null,
            isset($metrics['lastShipmentOn']) ? (string) $metrics['lastShipmentOn'] : null,
            isset($profile['createdAt']) ? (string) $profile['createdAt'] : null,
            isset($profile['updatedAt']) ? (string) $profile['updatedAt'] : null,
            $this->rewardAdjustmentPoints((string) $profile['customerKey']),
            $this->rewardEarnedAdjustmentPoints((string) $profile['customerKey']),
            intdiv((int) ($metrics['totalWeightGrams'] ?? 0), 100),
        );
    }

    private function rewardAdjustmentPoints(string $customerKey): int
    {
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        return array_sum(array_map(
            static fn (mixed $adjustment): int => is_array($adjustment)
                && ($adjustment['customerKey'] ?? '') === $customerKey
                    ? (int) ($adjustment['pointsDelta'] ?? 0)
                    : 0,
            is_array($adjustments) ? $adjustments : [],
        ));
    }

    private function rewardEarnedAdjustmentPoints(string $customerKey): int
    {
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        return array_sum(array_map(
            static fn (mixed $adjustment): int => is_array($adjustment)
                && ($adjustment['customerKey'] ?? '') === $customerKey
                    ? max(0, (int) ($adjustment['pointsDelta'] ?? 0))
                    : 0,
            is_array($adjustments) ? $adjustments : [],
        ));
    }

    private function weightToGrams(string $weightKg): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', trim($weightKg), $matches) !== 1) {
            return 0;
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        return ((int) $matches[1] * 1000) + (int) $fraction;
    }

    private function key(string $name): string
    {
        return hash('sha256', strtolower(trim($name)));
    }
}
