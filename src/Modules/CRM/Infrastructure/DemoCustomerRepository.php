<?php

declare(strict_types=1);

namespace App\Modules\CRM\Infrastructure;

use App\Modules\CRM\Domain\CustomerMergePolicy;
use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use App\Modules\Pickupsheet\Domain\PickupShipment;
use InvalidArgumentException;
use RuntimeException;

final class DemoCustomerRepository implements CustomerRepository
{
    private const SESSION_KEY = '_demo_pickup_customers';
    private const REWARDS_SESSION_KEY = '_demo_pickup_customer_rewards';
    private const ALIASES_SESSION_KEY = '_demo_pickup_customer_aliases';
    private const MERGES_SESSION_KEY = '_demo_pickup_customer_merges';
    private const ACTIVITIES_SESSION_KEY = '_demo_pickup_customer_activities';
    private const ERASED_SESSION_KEY = '_demo_pickup_customer_erased_names';
    private const DUPLICATE_DISMISSALS_SESSION_KEY = '_demo_pickup_customer_duplicate_dismissals';
    private const PROFILE_CHANGED_MESSAGE = 'This profile was changed by someone else after you opened it. Your changes were not saved; review the current details and edit again.';

    public function __construct(private readonly PickupSheetRepository $pickupSheets)
    {
    }

    public function synchronizeFromShipments(): void
    {
        $this->resolveShipmentAliases();
        $profiles = $this->profiles();
        $profileNames = [];
        foreach ($profiles as $profileKey => $profile) {
            if (is_array($profile)) {
                $profiles[$profileKey]['countryCode'] = 'CM';
                $profileNames[$this->key((string) ($profile['displayName'] ?? ''))] = true;
            }
        }
        $erasedNames = $_SESSION[self::ERASED_SESSION_KEY] ?? [];
        foreach ($this->metrics() as $key => $metric) {
            if (isset($profiles[$key]) || isset($profileNames[$key]) || isset($erasedNames[$key])) {
                continue;
            }
            $profiles[$key] = [
                'id' => $this->nextId($profiles),
                'customerKey' => $key,
                'displayName' => $metric['displayName'],
                'contactName' => '',
                'email' => '',
                'phone' => '',
                'address' => '',
                'city' => '',
                'countryCode' => 'CM',
                'status' => 'active',
                'notes' => '',
                'nextFollowUpOn' => null,
                'source' => 'shipment',
                'createdAt' => gmdate('Y-m-d H:i:s'),
                'updatedAt' => gmdate('Y-m-d H:i:s'),
            ];
            $profileNames[$key] = true;
        }
        $_SESSION[self::SESSION_KEY] = $profiles;
    }

    public function paginated(array $filters, int $limit, int $offset): array
    {
        $metrics = $this->metrics();
        $search = strtolower((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $followUp = (string) ($filters['followUp'] ?? '');
        $owner = (string) ($filters['owner'] ?? '');
        $today = gmdate('Y-m-d');
        $aliasNames = [];
        foreach ($this->aliases() as $alias) {
            $aliasNames[(string) $alias['customerKey']][] = (string) $alias['aliasName'];
        }
        $profiles = array_values(array_filter($this->profiles(), static function (array $profile) use ($search, $status, $followUp, $owner, $today, $aliasNames): bool {
            if ($status !== '' && ($profile['status'] ?? '') !== $status) {
                return false;
            }
            $nextFollowUpOn = $profile['nextFollowUpOn'] ?? null;
            $matchesFollowUp = match ($followUp) {
                'due' => $nextFollowUpOn !== null && $nextFollowUpOn <= $today && ($profile['status'] ?? '') !== 'inactive',
                'scheduled' => $nextFollowUpOn !== null && $nextFollowUpOn > $today,
                'none' => $nextFollowUpOn === null,
                default => true,
            };
            if (!$matchesFollowUp) {
                return false;
            }
            $assignedActorId = $profile['assignedActorId'] ?? null;
            if (($owner === 'unassigned' && $assignedActorId !== null)
                || (preg_match('/^[a-f0-9]{24}$/', $owner) === 1 && $assignedActorId !== $owner)) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $searchId = CustomerProfile::idFromReference($search);
            if ($searchId !== null && $searchId === (int) ($profile['id'] ?? 0)) {
                return true;
            }
            $phoneDigits = CustomerProfile::phoneSearchDigits($search);
            if ($phoneDigits !== null && str_contains(preg_replace('/\D+/', '', (string) ($profile['phone'] ?? '')) ?? '', $phoneDigits)) {
                return true;
            }
            $haystack = implode(' ', [
                $profile['displayName'] ?? '', $profile['contactName'] ?? '', $profile['email'] ?? '',
                $profile['phone'] ?? '', $profile['city'] ?? '', ...($aliasNames[(string) $profile['customerKey']] ?? []),
            ]);
            return str_contains(strtolower($haystack), $search);
        }));
        $items = array_map(
            fn (array $profile): CustomerProfile => $this->profile($profile, $metrics[$this->key((string) ($profile['displayName'] ?? ''))] ?? []),
            $profiles,
        );
        $byName = static fn (CustomerProfile $left, CustomerProfile $right): int => strcasecmp($left->displayName, $right->displayName);
        usort($items, match ((string) ($filters['sort'] ?? 'priority')) {
            'name' => $byName,
            'last_shipment' => static fn (CustomerProfile $left, CustomerProfile $right): int => strcmp((string) $right->lastShipmentOn, (string) $left->lastShipmentOn) ?: $byName($left, $right),
            'value' => static fn (CustomerProfile $left, CustomerProfile $right): int => ($right->totalCashXaf <=> $left->totalCashXaf) ?: $byName($left, $right),
            'points' => static fn (CustomerProfile $left, CustomerProfile $right): int => ($right->rewardBalance() <=> $left->rewardBalance()) ?: $byName($left, $right),
            default => static fn (CustomerProfile $left, CustomerProfile $right): int => ($right->followUpDue($today) <=> $left->followUpDue($today))
                ?: strcmp($left->displayName, $right->displayName),
        });

        return [
            'items' => array_slice($items, max(0, $offset), max(1, $limit)),
            'totalRecords' => count($items),
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

    public function topByRewardPoints(int $limit): array
    {
        $metrics = $this->metrics();
        $profiles = array_map(
            fn (array $profile): CustomerProfile => $this->profile(
                $profile,
                $metrics[$this->key((string) ($profile['displayName'] ?? ''))] ?? [],
            ),
            array_values($this->profiles()),
        );
        usort($profiles, static function (CustomerProfile $left, CustomerProfile $right): int {
            return ($right->rewardBalance() <=> $left->rewardBalance())
                ?: ($right->lifetimeEarnedPoints() <=> $left->lifetimeEarnedPoints())
                ?: (strcasecmp($left->displayName, $right->displayName) ?: strcmp($left->displayName, $right->displayName));
        });

        return array_slice($profiles, 0, max(1, min($limit, 10)));
    }

    public function suggestions(string $query, int $limit): array
    {
        $normalizedQuery = strtolower(trim($query));
        $names = array_values(array_filter(array_map(
            static fn (array $profile): string => trim((string) ($profile['displayName'] ?? '')),
            array_values($this->profiles()),
        ), static fn (string $name): bool => $name !== '' && str_starts_with(strtolower($name), $normalizedQuery)));
        usort($names, static function (string $left, string $right) use ($normalizedQuery): int {
            $leftExact = strtolower($left) === $normalizedQuery;
            $rightExact = strtolower($right) === $normalizedQuery;
            return ($rightExact <=> $leftExact)
                ?: (strlen($left) <=> strlen($right))
                ?: (strcasecmp($left, $right) ?: strcmp($left, $right));
        });
        return array_slice($names, 0, max(1, min($limit, 20)));
    }

    public function duplicateReviewNames(int $limit): array
    {
        return array_map(static fn (array $profile): array => [
            'customerKey' => (string) $profile['customerKey'],
            'displayName' => (string) ($profile['displayName'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
        ], array_slice(array_values($this->profiles()), 0, max(1, $limit)));
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        $profile = $this->profiles()[$customerKey] ?? null;
        return is_array($profile)
            ? $this->profile($profile, $this->metrics()[$this->key((string) ($profile['displayName'] ?? ''))] ?? [])
            : null;
    }

    public function findByName(string $name): ?array
    {
        $nameKey = $this->key($name);
        foreach ($this->profiles() as $profile) {
            if (is_array($profile) && $this->key((string) ($profile['displayName'] ?? '')) === $nameKey) {
                $customer = $this->find((string) $profile['customerKey']);
                return $customer === null ? null : ['customer' => $customer, 'alias' => null];
            }
        }
        $alias = $this->aliases()[$nameKey] ?? null;
        if (!is_array($alias)) {
            return null;
        }
        $customer = $this->find((string) $alias['customerKey']);
        return $customer === null ? null : ['customer' => $customer, 'alias' => (string) $alias['aliasName']];
    }

    public function recentShipments(string $customerKey, int $limit, int $offset = 0): array
    {
        $profile = $this->profiles()[$customerKey] ?? null;
        if (!is_array($profile)) {
            return [];
        }
        $profileNameKey = $this->key((string) ($profile['displayName'] ?? ''));
        $shipments = [];
        foreach ($this->pickupSheets->recent(PHP_INT_MAX) as $sheet) {
            foreach ($sheet->shipments as $shipment) {
                if ($this->key($shipment->consignor) !== $profileNameKey) {
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
        return array_slice($shipments, max(0, $offset), max(1, $limit));
    }

    public function shipmentCount(string $customerKey): int
    {
        return count($this->recentShipments($customerKey, PHP_INT_MAX));
    }

    public function save(CustomerProfile $customer, string $actorId, ?string $expectedUpdatedAt = null): CustomerProfile
    {
        $profiles = $this->profiles();
        $existing = $profiles[$customer->customerKey] ?? null;
        if ($customer->id === null && is_array($existing)) {
            throw new InvalidArgumentException('A customer profile already uses this organization name.');
        }
        if ($expectedUpdatedAt !== null && is_array($existing) && (string) ($existing['updatedAt'] ?? '') !== $expectedUpdatedAt) {
            throw new InvalidArgumentException(self::PROFILE_CHANGED_MESSAGE);
        }
        foreach ($profiles as $key => $profile) {
            if ($key !== $customer->customerKey
                && is_array($profile)
                && $this->key((string) ($profile['displayName'] ?? '')) === $this->key($customer->displayName)) {
                throw new InvalidArgumentException('A customer profile already uses this organization name.');
            }
        }
        $aliases = $this->aliases();
        $aliasOwner = $aliases[$this->key($customer->displayName)]['customerKey'] ?? null;
        if (is_string($aliasOwner) && $aliasOwner !== $customer->customerKey) {
            throw new InvalidArgumentException('This organization name was merged into another customer profile.');
        }
        if (is_string($aliasOwner)) {
            unset($aliases[$this->key($customer->displayName)]);
            $_SESSION[self::ALIASES_SESSION_KEY] = $aliases;
        }
        if (is_array($existing)
            && trim((string) ($existing['displayName'] ?? '')) !== trim($customer->displayName)) {
            $this->renameExistingShipments(
                (string) ($existing['displayName'] ?? ''),
                $customer->displayName,
                $actorId,
            );
        }
        $now = gmdate('Y-m-d H:i:s');
        $profiles[$customer->customerKey] = [
            'id' => is_array($existing) ? (int) $existing['id'] : $this->nextId($profiles),
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
            'assignedActorId' => $customer->assignedActorId,
            'assignedName' => $customer->assignedActorId === null ? '' : $customer->assignedName,
            'createdAt' => is_array($existing) ? $existing['createdAt'] : $now,
            'updatedAt' => $now,
        ];
        $_SESSION[self::SESSION_KEY] = $profiles;
        return $this->find($customer->customerKey) ?? $customer;
    }

    public function delete(string $customerKey, string $actorId): void
    {
        $profiles = $this->profiles();
        $profile = $profiles[$customerKey] ?? null;
        if (!is_array($profile)) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        unset($profiles[$customerKey]);
        $_SESSION[self::SESSION_KEY] = $profiles;
        $keep = static fn (mixed $row): bool => !is_array($row) || ($row['customerKey'] ?? '') !== $customerKey;
        $_SESSION[self::REWARDS_SESSION_KEY] = array_filter(is_array($_SESSION[self::REWARDS_SESSION_KEY] ?? null) ? $_SESSION[self::REWARDS_SESSION_KEY] : [], $keep);
        $_SESSION[self::ACTIVITIES_SESSION_KEY] = array_values(array_filter($this->allActivities(), $keep));
        $_SESSION[self::ALIASES_SESSION_KEY] = array_filter($this->aliases(), $keep);
        $_SESSION[self::DUPLICATE_DISMISSALS_SESSION_KEY] = array_values(array_filter(
            $this->dismissedDuplicatePairs(),
            static fn (string $pair): bool => !in_array($customerKey, explode(':', $pair), true),
        ));
        $_SESSION[self::MERGES_SESSION_KEY] = array_map(
            static fn (array $merge): array => in_array($customerKey, [$merge['targetCustomerKey'] ?? '', $merge['sourceCustomerKey'] ?? ''], true)
                ? ['undoneAt' => 'erased', 'snapshot' => []] + $merge
                : $merge,
            $this->merges(),
        );
        // Do not rebuild the erased profile from sheets that already carry the name.
        $erased = is_array($_SESSION[self::ERASED_SESSION_KEY] ?? null) ? $_SESSION[self::ERASED_SESSION_KEY] : [];
        $erased[$this->key((string) $profile['displayName'])] = true;
        $_SESSION[self::ERASED_SESSION_KEY] = $erased;
    }

    public function activities(string $customerKey, int $limit): array
    {
        $activities = array_values(array_filter(
            $this->allActivities(),
            static fn (array $activity): bool => ($activity['customerKey'] ?? '') === $customerKey,
        ));
        usort($activities, static fn (array $left, array $right): int => strcmp((string) $right['occurredOn'], (string) $left['occurredOn'])
            ?: ((int) $right['id'] <=> (int) $left['id']));
        return array_map(static fn (array $activity): array => [
            'type' => (string) $activity['type'],
            'occurredOn' => (string) $activity['occurredOn'],
            'summary' => (string) $activity['summary'],
            'actorId' => (string) $activity['actorId'],
            'actorName' => (string) $activity['actorName'],
            'createdAt' => (string) $activity['createdAt'],
        ], array_slice($activities, 0, max(1, min($limit, 100))));
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
        $profiles = $this->profiles();
        if (!is_array($profiles[$customerKey] ?? null)) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        $activities = $this->allActivities();
        $activities[] = [
            'id' => count($activities) + 1,
            'customerKey' => $customerKey,
            'type' => $type,
            'occurredOn' => $occurredOn,
            'summary' => $summary,
            'actorId' => $actorId,
            'actorName' => $actorName,
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ];
        $_SESSION[self::ACTIVITIES_SESSION_KEY] = $activities;
        $profiles[$customerKey]['nextFollowUpOn'] = $nextFollowUpOn;
        $profiles[$customerKey]['updatedAt'] = gmdate('Y-m-d H:i:s');
        $_SESSION[self::SESSION_KEY] = $profiles;

        return $this->find($customerKey) ?? throw new RuntimeException('Customer profile could not be loaded.');
    }

    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile
    {
        $profiles = $this->profiles();
        $target = $profiles[$targetCustomerKey] ?? null;
        $source = $profiles[$sourceCustomerKey] ?? null;
        if (!is_array($target) || !is_array($source)) {
            throw new InvalidArgumentException('One of the customer profiles no longer exists.');
        }

        $sourceNameKey = $this->key((string) $source['displayName']);
        $shipments = [];
        $this->rewriteShipments(function (PickupSheet $sheet, PickupShipment $shipment) use ($sourceNameKey, $target, &$shipments): ?string {
            if ($this->key($shipment->consignor) !== $sourceNameKey) {
                return null;
            }
            $shipments[] = [$sheet->id, $shipment->lineNumber, $shipment->consignor];
            return (string) $target['displayName'];
        }, $actorId);

        $targetBefore = $this->mergeFields($target);
        $targetAfter = CustomerMergePolicy::merge($targetBefore, $this->mergeFields($source) + ['displayName' => (string) $source['displayName']]);
        $profiles[$targetCustomerKey] = $targetAfter + $target;
        $profiles[$targetCustomerKey]['updatedAt'] = gmdate('Y-m-d H:i:s');
        unset($profiles[$sourceCustomerKey]);
        $_SESSION[self::SESSION_KEY] = $profiles;

        $rewardIndexes = [];
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        if (is_array($adjustments)) {
            foreach ($adjustments as $index => $adjustment) {
                if (is_array($adjustment) && ($adjustment['customerKey'] ?? '') === $sourceCustomerKey) {
                    $adjustments[$index]['customerKey'] = $targetCustomerKey;
                    $rewardIndexes[] = $index;
                }
            }
            $_SESSION[self::REWARDS_SESSION_KEY] = $adjustments;
        }

        $aliases = $this->aliases();
        $movedAliases = [];
        foreach ($aliases as $aliasKey => $alias) {
            if (($alias['customerKey'] ?? '') === $sourceCustomerKey) {
                $aliases[$aliasKey]['customerKey'] = $targetCustomerKey;
                $movedAliases[] = $aliasKey;
            }
        }
        $aliases[$sourceNameKey] = ['aliasName' => trim((string) $source['displayName']), 'customerKey' => $targetCustomerKey];
        $_SESSION[self::ALIASES_SESSION_KEY] = $aliases;

        $activities = $this->allActivities();
        $activityIndexes = [];
        foreach ($activities as $index => $activity) {
            if (($activity['customerKey'] ?? '') === $sourceCustomerKey) {
                $activities[$index]['customerKey'] = $targetCustomerKey;
                $activityIndexes[] = $index;
            }
        }
        $_SESSION[self::ACTIVITIES_SESSION_KEY] = $activities;

        $merges = $this->merges();
        $merges[] = [
            'id' => count($merges) + 1,
            'targetCustomerKey' => $targetCustomerKey,
            'sourceCustomerKey' => $sourceCustomerKey,
            'sourceName' => (string) $source['displayName'],
            'mergedAt' => gmdate('Y-m-d H:i:s'),
            'undoneAt' => null,
            'snapshot' => [
                'source' => $source,
                'targetBefore' => $targetBefore,
                'targetAfter' => $targetAfter,
                'shipments' => $shipments,
                'rewardIndexes' => $rewardIndexes,
                'aliasKeys' => $movedAliases,
                'activityIndexes' => $activityIndexes,
            ],
        ];
        $_SESSION[self::MERGES_SESSION_KEY] = $merges;

        return $this->find($targetCustomerKey) ?? throw new RuntimeException('Merged customer profile could not be loaded.');
    }

    public function recentMerges(int $limit): array
    {
        $profiles = $this->profiles();
        $recent = [];
        foreach (array_reverse($this->merges()) as $merge) {
            $target = $profiles[$merge['targetCustomerKey'] ?? ''] ?? null;
            if (($merge['undoneAt'] ?? null) !== null || ($merge['dismissedAt'] ?? null) !== null || !is_array($target)) {
                continue;
            }
            $recent[] = [
                'id' => (int) $merge['id'],
                'targetCustomerKey' => (string) $merge['targetCustomerKey'],
                'targetName' => (string) $target['displayName'],
                'sourceName' => (string) $merge['sourceName'],
                'mergedAt' => (string) $merge['mergedAt'],
            ];
        }
        return array_slice($recent, 0, max(1, min($limit, 20)));
    }

    public function undoMerge(int $mergeId, string $actorId): CustomerProfile
    {
        $merges = $this->merges();
        $index = $mergeId - 1;
        $merge = $merges[$index] ?? null;
        if (!is_array($merge) || ($merge['undoneAt'] ?? null) !== null || ($merge['dismissedAt'] ?? null) !== null) {
            throw new InvalidArgumentException('This merge has already been undone or no longer exists.');
        }
        $profiles = $this->profiles();
        $targetKey = (string) $merge['targetCustomerKey'];
        $sourceKey = (string) $merge['sourceCustomerKey'];
        $snapshot = $merge['snapshot'];
        $source = $snapshot['source'];
        $target = $profiles[$targetKey] ?? null;
        if (!is_array($target)) {
            throw new InvalidArgumentException('The retained profile was merged again or removed. Undo that later merge first.');
        }
        $sourceNameKey = $this->key((string) $source['displayName']);
        foreach ($profiles as $key => $profile) {
            if ($key === $sourceKey || $this->key((string) ($profile['displayName'] ?? '')) === $sourceNameKey) {
                throw new InvalidArgumentException(sprintf('Another customer profile now uses the name %s. Rename it before undoing this merge.', (string) $source['displayName']));
            }
        }

        $aliases = $this->aliases();
        if (($aliases[$sourceNameKey]['customerKey'] ?? null) === $targetKey) {
            unset($aliases[$sourceNameKey]);
        }
        foreach ($snapshot['aliasKeys'] as $aliasKey) {
            if (($aliases[$aliasKey]['customerKey'] ?? null) === $targetKey) {
                $aliases[$aliasKey]['customerKey'] = $sourceKey;
            }
        }
        $_SESSION[self::ALIASES_SESSION_KEY] = $aliases;

        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        foreach ($snapshot['rewardIndexes'] as $rewardIndex) {
            if (($adjustments[$rewardIndex]['customerKey'] ?? null) === $targetKey) {
                $adjustments[$rewardIndex]['customerKey'] = $sourceKey;
            }
        }
        $_SESSION[self::REWARDS_SESSION_KEY] = $adjustments;

        $activities = $this->allActivities();
        foreach ($snapshot['activityIndexes'] ?? [] as $activityIndex) {
            if (($activities[$activityIndex]['customerKey'] ?? null) === $targetKey) {
                $activities[$activityIndex]['customerKey'] = $sourceKey;
            }
        }
        $_SESSION[self::ACTIVITIES_SESSION_KEY] = $activities;

        // Only shipments still attributed to the retained profile are handed back.
        $restoredShipments = [];
        foreach ($snapshot['shipments'] as [$sheetId, $lineNumber, $consignor]) {
            $restoredShipments[$sheetId . ':' . $lineNumber] = $consignor;
        }
        $targetNameKey = $this->key((string) $target['displayName']);
        $this->rewriteShipments(fn (PickupSheet $sheet, PickupShipment $shipment): ?string => $this->key($shipment->consignor) === $targetNameKey
            ? ($restoredShipments[$sheet->id . ':' . $shipment->lineNumber] ?? null)
            : null, $actorId);

        // Restore target fields only where nobody has edited them since the merge.
        $current = $this->mergeFields($target);
        foreach (CustomerMergePolicy::MERGED_FIELDS as $field) {
            if ((string) ($current[$field] ?? '') === (string) ($snapshot['targetAfter'][$field] ?? '')) {
                $target[$field] = $snapshot['targetBefore'][$field] ?? $target[$field] ?? null;
            }
        }
        $profiles = $this->profiles();
        $profiles[$targetKey] = $target;
        $profiles[$sourceKey] = $source;
        $_SESSION[self::SESSION_KEY] = $profiles;

        $merges[$index]['undoneAt'] = gmdate('Y-m-d H:i:s');
        $_SESSION[self::MERGES_SESSION_KEY] = $merges;

        return $this->find($sourceKey) ?? throw new RuntimeException('Restored customer profile could not be loaded.');
    }

    public function dismissMerge(int $mergeId, string $actorId): void
    {
        $merges = $this->merges();
        $merge = $merges[$mergeId - 1] ?? null;
        if (!is_array($merge) || ($merge['undoneAt'] ?? null) !== null || ($merge['dismissedAt'] ?? null) !== null) {
            throw new InvalidArgumentException('This merge has already been undone, ignored, or no longer exists.');
        }
        $merges[$mergeId - 1]['dismissedAt'] = gmdate('Y-m-d H:i:s');
        $merges[$mergeId - 1]['dismissedBy'] = $actorId;
        $_SESSION[self::MERGES_SESSION_KEY] = $merges;
    }

    public function dismissedDuplicatePairs(): array
    {
        $pairs = $_SESSION[self::DUPLICATE_DISMISSALS_SESSION_KEY] ?? [];
        return is_array($pairs) ? array_values(array_filter($pairs, 'is_string')) : [];
    }

    public function dismissDuplicate(string $firstCustomerKey, string $secondCustomerKey, string $actorId): void
    {
        $pairs = $this->dismissedDuplicatePairs();
        $pair = $firstCustomerKey . ':' . $secondCustomerKey;
        if (!in_array($pair, $pairs, true)) {
            $pairs[] = $pair;
        }
        $_SESSION[self::DUPLICATE_DISMISSALS_SESSION_KEY] = $pairs;
    }

    /** Points shipments still typed with a merged-away name at the profile that absorbed it. */
    private function resolveShipmentAliases(): void
    {
        $aliases = $this->aliases();
        if ($aliases === []) {
            return;
        }
        $profiles = $this->profiles();
        $this->rewriteShipments(function (PickupSheet $sheet, PickupShipment $shipment) use ($aliases, $profiles): ?string {
            $owner = $aliases[$this->key($shipment->consignor)]['customerKey'] ?? null;
            return is_string($owner) && is_array($profiles[$owner] ?? null) ? (string) $profiles[$owner]['displayName'] : null;
        }, str_repeat('0', 24));
    }

    private function renameExistingShipments(string $previousName, string $newName, string $actorId): void
    {
        $previousKey = $this->key($previousName);
        $this->rewriteShipments(
            fn (PickupSheet $sheet, PickupShipment $shipment): ?string => $this->key($shipment->consignor) === $previousKey ? $newName : null,
            $actorId,
        );
    }

    /** @param callable(PickupSheet, PickupShipment): ?string $consignorFor returns a replacement consignor, or null to keep the current one */
    private function rewriteShipments(callable $consignorFor, string $actorId): void
    {
        foreach ($this->pickupSheets->recent(PHP_INT_MAX) as $sheet) {
            if (!$sheet instanceof PickupSheet) {
                continue;
            }
            $changed = false;
            $shipments = [];
            foreach ($sheet->shipments as $shipment) {
                $consignor = $consignorFor($sheet, $shipment);
                if ($consignor === null || $consignor === $shipment->consignor) {
                    $shipments[] = $shipment;
                    continue;
                }
                $changed = true;
                $shipments[] = new PickupShipment(
                    $shipment->lineNumber,
                    $consignor,
                    $shipment->awbNumber,
                    $shipment->destination,
                    $shipment->amountXaf,
                    $shipment->pieces,
                    $shipment->weightKg,
                    $shipment->collectionTime,
                    $shipment->checkedBy,
                );
            }
            if (!$changed) {
                continue;
            }
            $this->pickupSheets->update(new PickupSheet(
                $sheet->id,
                $sheet->referenceNumber,
                $sheet->agentName,
                $sheet->collectionDate,
                $shipments,
                $sheet->totalCashReceivedXaf,
                $sheet->privacyConsentAt,
                $sheet->privacyNoticeVersion,
                $sheet->createdAt,
                $sheet->status,
                $sheet->paidAt,
                $sheet->paymentReceiptNumber,
            ), $actorId);
        }
    }

    public function rewardHistory(string $customerKey, int $limit, int $offset = 0): array
    {
        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        $history = array_values(array_filter(
            is_array($adjustments) ? $adjustments : [],
            static fn (mixed $adjustment): bool => is_array($adjustment) && ($adjustment['customerKey'] ?? '') === $customerKey,
        ));

        return array_map(static fn (array $adjustment): array => [
            'pointsDelta' => (int) ($adjustment['pointsDelta'] ?? 0),
            'reason' => (string) ($adjustment['reason'] ?? ''),
            'actorId' => (string) ($adjustment['actorId'] ?? ''),
            'actorName' => (string) ($adjustment['actorName'] ?? ''),
            'createdAt' => (string) ($adjustment['createdAt'] ?? ''),
        ], array_slice(array_reverse($history), max(0, $offset), max(1, min($limit, 50))));
    }

    public function rewardHistoryCount(string $customerKey): int
    {
        return count($this->rewardHistory($customerKey, PHP_INT_MAX));
    }

    public function activityCount(string $customerKey): int
    {
        return count(array_filter(
            $this->allActivities(),
            static fn (array $activity): bool => ($activity['customerKey'] ?? '') === $customerKey,
        ));
    }

    public function addRewardAdjustment(
        string $customerKey,
        int $pointsDelta,
        string $reason,
        string $actorId,
        string $actorName = '',
    ): CustomerProfile
    {
        $customer = $this->find($customerKey);
        if ($customer === null) {
            throw new RuntimeException('Customer profile not found for reward adjustment.');
        }
        if ($pointsDelta < 0 && $customer->rewardBalance() + $pointsDelta < 0) {
            throw new InvalidArgumentException('A redemption cannot exceed the available reward balance.');
        }

        $adjustments = $_SESSION[self::REWARDS_SESSION_KEY] ?? [];
        $adjustments = is_array($adjustments) ? $adjustments : [];
        $adjustments[] = [
            'customerKey' => $customerKey,
            'pointsDelta' => $pointsDelta,
            'reason' => $reason,
            'actorId' => $actorId,
            'actorName' => $actorName,
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
            (string) ($profile['countryCode'] ?? 'CM'),
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
            isset($profile['assignedActorId']) ? (string) $profile['assignedActorId'] : null,
            (string) ($profile['assignedName'] ?? ''),
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

    /**
     * @param array<string, mixed> $profile
     * @return array{contactName: string, email: string, phone: string, address: string, city: string, status: string, nextFollowUpOn: ?string, notes: string}
     */
    private function mergeFields(array $profile): array
    {
        return [
            'contactName' => (string) ($profile['contactName'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
            'city' => (string) ($profile['city'] ?? ''),
            'status' => (string) ($profile['status'] ?? 'active'),
            'nextFollowUpOn' => is_string($profile['nextFollowUpOn'] ?? null) && $profile['nextFollowUpOn'] !== '' ? $profile['nextFollowUpOn'] : null,
            'notes' => (string) ($profile['notes'] ?? ''),
        ];
    }

    /** @return array<string, array{aliasName: string, customerKey: string}> */
    private function aliases(): array
    {
        $aliases = $_SESSION[self::ALIASES_SESSION_KEY] ?? [];
        return is_array($aliases) ? $aliases : [];
    }

    /** @return list<array<string, mixed>> */
    private function allActivities(): array
    {
        $activities = $_SESSION[self::ACTIVITIES_SESSION_KEY] ?? [];
        return is_array($activities) ? $activities : [];
    }

    /** @param array<string, array<string, mixed>> $profiles */
    private function nextId(array $profiles): int
    {
        return max([0, ...array_map(static fn (array $profile): int => (int) ($profile['id'] ?? 0), $profiles)]) + 1;
    }

    /** @return list<array<string, mixed>> */
    private function merges(): array
    {
        $merges = $_SESSION[self::MERGES_SESSION_KEY] ?? [];
        return is_array($merges) ? array_values($merges) : [];
    }

    private function key(string $name): string
    {
        return hash('sha256', strtolower(trim($name)));
    }
}
