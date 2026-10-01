<?php

declare(strict_types=1);

namespace App\Modules\CRM\Application;

use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Normalizer;

final class CustomerService
{
    private const STATUSES = ['lead', 'active', 'attention', 'inactive'];
    private const DEFAULT_COUNTRY_CODE = 'CM';
    private const COUNTRY_CALLING_CODES = ['CM' => '+237'];
    private const DUPLICATE_CONFIDENCE_THRESHOLD = 88;
    private const DUPLICATE_REVIEW_MAX_PROFILES = 20000;
    /** A contact shared by more profiles than this is treated as a switchboard, not as a duplicate signal. */
    private const SHARED_CONTACT_GROUP_LIMIT = 25;
    public const ACTIVITY_TYPES = ['call' => 'Call', 'visit' => 'Visit', 'email' => 'Email', 'meeting' => 'Meeting', 'note' => 'Note'];
    public const SORT_OPTIONS = [
        'priority' => 'Priority',
        'name' => 'Name A-Z',
        'last_shipment' => 'Latest shipment',
        'value' => 'Shipment value',
        'points' => 'Reward points',
    ];
    public const EXPORT_ROW_LIMIT = 5000;

    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $filters raw search, status, follow_up, owner, and sort values
     * @return array{items: list<CustomerProfile>, page: int, perPage: int, totalRecords: int, totalPages: int}
     */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $this->repository->synchronizeFromShipments();
        $filters = $this->directoryFilters($filters);
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);
        $result = $this->repository->paginated($filters, $perPage, ($page - 1) * $perPage);
        $totalPages = max(1, (int) ceil($result['totalRecords'] / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $result = $this->repository->paginated($filters, $perPage, ($page - 1) * $perPage);
        }

        return [
            'items' => $result['items'],
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $result['totalRecords'],
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Every profile matching the directory filters, for spreadsheet export.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<CustomerProfile>, totalRecords: int}
     */
    public function exportRows(array $filters): array
    {
        $this->repository->synchronizeFromShipments();
        return $this->repository->paginated($this->directoryFilters($filters), self::EXPORT_ROW_LIMIT, 0);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{search: string, status: string, followUp: string, owner: string, sort: string}
     */
    public function directoryFilters(array $input): array
    {
        $value = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $owner = strtolower($value('owner'));
        return [
            'search' => substr($value('search'), 0, 100),
            'status' => in_array($value('status'), self::STATUSES, true) ? $value('status') : '',
            'followUp' => in_array($value('follow_up'), ['due', 'scheduled', 'none'], true) ? $value('follow_up') : '',
            'owner' => $owner === 'unassigned' || preg_match('/^[a-f0-9]{24}$/', $owner) === 1 ? $owner : '',
            'sort' => array_key_exists($value('sort'), self::SORT_OPTIONS) ? $value('sort') : 'priority',
        ];
    }

    /** @return array{customerCount: int, activeCount: int, attentionCount: int, followUpsDue: int} */
    public function summary(): array
    {
        $this->repository->synchronizeFromShipments();
        return $this->repository->summary();
    }

    /** @return list<CustomerProfile> */
    public function topByPoints(int $limit = 5): array
    {
        $this->repository->synchronizeFromShipments();
        return $this->repository->topByRewardPoints(max(1, min($limit, 10)));
    }

    public function synchronize(): void
    {
        $this->repository->synchronizeFromShipments();
    }

    /**
     * Autocomplete reads existing profiles only; pages that open the form synchronize first, so
     * keystrokes never trigger a full shipment scan.
     *
     * @return list<string>
     */
    public function suggestions(string $query, int $limit = 12): array
    {
        $query = $this->text($query, 100);
        if ($query === '') {
            return [];
        }
        return $this->repository->suggestions($query, max(1, min($limit, 20)));
    }

    /** @return array{customer: CustomerProfile, alias: ?string}|null */
    public function existingCustomer(string $name): ?array
    {
        $name = $this->collapsedName($this->text($name, 160));
        return strlen($name) < 2 ? null : $this->repository->findByName($name);
    }

    /**
     * Pairs of profiles that are probably the same customer: near-identical names, or a shared email
     * address or phone number. Names that differ by a number (branches, sites) are never paired.
     *
     * @return list<array{primary: CustomerProfile, duplicate: CustomerProfile, confidence: int, reason: string}>
     */
    public function duplicateSuggestions(int $limit = 8): array
    {
        $this->repository->synchronizeFromShipments();
        $entries = [];
        foreach ($this->repository->duplicateReviewNames(self::DUPLICATE_REVIEW_MAX_PROFILES) as $row) {
            $normalized = $this->normalizedDuplicateName($row['displayName']);
            if ($normalized !== '') {
                $entries[] = [
                    'key' => $row['customerKey'],
                    'name' => $row['displayName'],
                    'normalized' => $normalized,
                    'email' => strtolower(trim($row['email'])),
                    'phone' => $this->phoneDigits($row['phone']),
                ];
            }
        }

        // Compare only names that share their first or last three characters. A small edit leaves
        // at least one end intact, so this keeps recall while avoiding an all-pairs comparison.
        $nameBlocks = [];
        $contactBlocks = [];
        foreach ($entries as $index => $entry) {
            $nameBlocks['p' . substr($entry['normalized'], 0, 3)][] = $index;
            $nameBlocks['s' . substr($entry['normalized'], -3)][] = $index;
            if ($entry['email'] !== '') {
                $contactBlocks['e' . $entry['email']][] = $index;
            }
            if ($entry['phone'] !== '') {
                $contactBlocks['t' . $entry['phone']][] = $index;
            }
        }
        $candidates = [];
        $dismissed = array_flip($this->repository->dismissedDuplicatePairs());
        $consider = function (array $left, array $right) use (&$candidates, $dismissed): void {
            $pairKey = strcmp($left['key'], $right['key']) < 0 ? $left['key'] . ':' . $right['key'] : $right['key'] . ':' . $left['key'];
            if (isset($dismissed[$pairKey])) {
                return;
            }
            if (!array_key_exists($pairKey, $candidates)) {
                $match = $this->duplicateMatch($left, $right);
                $candidates[$pairKey] = $match === null ? null : ['left' => $left, 'right' => $right] + $match;
            }
        };
        foreach ($nameBlocks as $members) {
            $memberCount = count($members);
            if ($memberCount < 2) {
                continue;
            }
            usort($members, static fn (int $left, int $right): int => strlen($entries[$left]['normalized']) <=> strlen($entries[$right]['normalized']));
            for ($leftIndex = 0; $leftIndex < $memberCount; $leftIndex++) {
                $left = $entries[$members[$leftIndex]];
                for ($rightIndex = $leftIndex + 1; $rightIndex < $memberCount; $rightIndex++) {
                    $right = $entries[$members[$rightIndex]];
                    $rightLength = strlen($right['normalized']);
                    // Scores at the threshold need a length ratio of roughly 0.8 or at most three edits.
                    if ($rightLength - strlen($left['normalized']) > max(3, (int) ceil($rightLength * 0.2))) {
                        break;
                    }
                    $consider($left, $right);
                }
            }
        }
        foreach ($contactBlocks as $members) {
            $memberCount = count($members);
            if ($memberCount < 2 || $memberCount > self::SHARED_CONTACT_GROUP_LIMIT) {
                continue;
            }
            for ($leftIndex = 0; $leftIndex < $memberCount; $leftIndex++) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $memberCount; $rightIndex++) {
                    $consider($entries[$members[$leftIndex]], $entries[$members[$rightIndex]]);
                }
            }
        }
        $matches = array_values(array_filter($candidates));
        usort($matches, static fn (array $left, array $right): int => ($right['confidence'] <=> $left['confidence'])
            ?: strcasecmp($left['left']['name'], $right['left']['name']));

        $profiles = [];
        $suggestions = [];
        foreach ($matches as $match) {
            $leftProfile = $profiles[$match['left']['key']] ??= $this->repository->find($match['left']['key']);
            $rightProfile = $profiles[$match['right']['key']] ??= $this->repository->find($match['right']['key']);
            if ($leftProfile === null || $rightProfile === null) {
                continue;
            }
            [$primary, $duplicate] = $this->preferredProfile($leftProfile, $rightProfile);
            $suggestions[] = ['primary' => $primary, 'duplicate' => $duplicate, 'confidence' => $match['confidence'], 'reason' => $match['reason']];
            if (count($suggestions) >= max(1, min($limit, 20))) {
                break;
            }
        }
        return $suggestions;
    }

    /** @return list<array{id: int, targetCustomerKey: string, targetName: string, sourceName: string, mergedAt: string}> */
    public function recentMerges(int $limit = 5): array
    {
        return $this->repository->recentMerges(max(1, min($limit, 20)));
    }

    public function find(string $customerKey): ?CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return null;
        }
        $this->repository->synchronizeFromShipments();
        return $this->repository->find($customerKey);
    }

    /** @return list<array<string, int|string>> */
    public function recentShipments(string $customerKey, int $limit = 20): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return [];
        }
        return $this->repository->recentShipments($customerKey, max(1, min($limit, 50)));
    }

    /** @return array{items: list<array<string, int|string>>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    public function paginatedShipments(string $customerKey, int $page = 1, int $perPage = 10): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return $this->emptyPage($perPage);
        }
        $perPage = max(1, min($perPage, 50));
        $totalRecords = $this->repository->shipmentCount($customerKey);
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = max(1, min($page, $totalPages));
        return [
            'items' => $this->repository->recentShipments($customerKey, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Bonuses and redemptions together, newest first.
     *
     * @return array{items: list<array{pointsDelta: int, reason: string, actorId: string, actorName: string, createdAt: string}>, page: int, perPage: int, totalRecords: int, totalPages: int}
     */
    public function paginatedRewardHistory(string $customerKey, int $page = 1, int $perPage = 10): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return $this->emptyPage($perPage);
        }
        $perPage = max(1, min($perPage, 50));
        $totalRecords = $this->repository->rewardHistoryCount($customerKey);
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = max(1, min($page, $totalPages));
        return [
            'items' => $this->repository->rewardHistory($customerKey, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
        ];
    }

    public function activityCount(string $customerKey): int
    {
        return preg_match('/^[a-f0-9]{64}$/', $customerKey) === 1 ? $this->repository->activityCount($customerKey) : 0;
    }

    public function adjustRewards(
        string $customerKey,
        string $operation,
        mixed $points,
        mixed $reason,
        string $actorId,
        string $actorName = '',
    ): CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The reward actor is invalid.');
        }
        $customer = $this->find($customerKey);
        if ($customer === null) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        if (!in_array($operation, ['bonus', 'redeem'], true)) {
            throw new InvalidArgumentException('Select a valid reward operation.');
        }
        $points = is_string($points) || is_int($points) ? trim((string) $points) : '';
        if (preg_match('/^[0-9]{1,6}$/', $points) !== 1 || (int) $points < 1 || (int) $points > 100000) {
            throw new InvalidArgumentException('Reward points must be between 1 and 100,000.');
        }
        $reason = $this->text($reason, 255);
        if (strlen($reason) < 3) {
            throw new InvalidArgumentException('Provide a reason for the reward adjustment.');
        }

        $pointsDelta = $operation === 'bonus' ? (int) $points : -(int) $points;
        if ($pointsDelta < 0 && $customer->rewardBalance() + $pointsDelta < 0) {
            throw new InvalidArgumentException('A redemption cannot exceed the available reward balance.');
        }

        return $this->repository->addRewardAdjustment(
            $customer->customerKey,
            $pointsDelta,
            $reason,
            $actorId,
            $this->text($actorName, 160),
        );
    }

    /** @param array<string, mixed> $input */
    public function save(?string $customerKey, array $input, string $actorId): CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }

        $existing = null;
        if ($customerKey !== null && $customerKey !== '') {
            $existing = $this->find($customerKey);
            if ($existing === null) {
                throw new InvalidArgumentException('Customer profile not found.');
            }
        }

        $displayName = $this->text($input['display_name'] ?? '', 160);
        if (strlen($displayName) < 2 || $this->containsControlCharacters($displayName)) {
            throw new InvalidArgumentException('Provide a customer or organization name.');
        }
        $displayName = $this->collapsedName($displayName);
        $nameOwner = $this->repository->findByName($displayName);
        if ($nameOwner !== null && $nameOwner['customer']->customerKey !== $existing?->customerKey) {
            throw new DuplicateCustomerException($nameOwner['customer'], $nameOwner['alias']);
        }
        $contactName = $this->text($input['contact_name'] ?? '', 100);
        $email = strtolower($this->text($input['email'] ?? '', 254));
        $phone = $this->text($input['phone'] ?? '', 32);
        $address = $this->text($input['address'] ?? '', 255);
        $city = $this->text($input['city'] ?? '', 100);
        $countryCode = strtoupper($this->text($input['country_code'] ?? self::DEFAULT_COUNTRY_CODE, 2));
        $countryCode = $countryCode === '' ? self::DEFAULT_COUNTRY_CODE : $countryCode;
        $status = strtolower($this->text($input['status'] ?? 'active', 20));
        $notes = $this->multilineText($input['notes'] ?? '', 2000);
        $nextFollowUpOn = $this->date($input['next_follow_up_on'] ?? '');

        if ($contactName !== '' && $this->containsControlCharacters($contactName)) {
            throw new InvalidArgumentException('The contact name contains invalid characters.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Provide a valid customer email address.');
        }
        if (!isset(self::COUNTRY_CALLING_CODES[$countryCode])) {
            throw new InvalidArgumentException('Customer country must be Cameroon.');
        }
        $phone = $this->phone($phone, $countryCode);
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Select a valid customer status.');
        }

        [$assignedActorId, $assignedName] = [$existing?->assignedActorId, $existing?->assignedName ?? ''];
        if (array_key_exists('owner_actor_id', $input)) {
            $assignedActorId = strtolower($this->text($input['owner_actor_id'] ?? '', 24));
            $assignedName = $this->text($input['owner_name'] ?? '', 160);
            if ($assignedActorId === '') {
                [$assignedActorId, $assignedName] = [null, ''];
            } elseif (preg_match('/^[a-f0-9]{24}$/', $assignedActorId) !== 1 || $assignedName === '') {
                throw new InvalidArgumentException('Select a valid customer owner.');
            }
        }
        $expectedUpdatedAt = $this->text($input['expected_updated_at'] ?? '', 32);

        $key = $existing?->customerKey ?? $this->newCustomerKey($displayName);
        $customer = new CustomerProfile(
            $existing?->id,
            $key,
            $displayName,
            $contactName,
            $email,
            $phone,
            $address,
            $city,
            $countryCode,
            $status,
            $notes,
            $nextFollowUpOn,
            $existing?->source ?? 'manual',
            $existing?->shipmentCount ?? 0,
            $existing?->totalCashXaf ?? 0,
            $existing?->firstShipmentOn,
            $existing?->lastShipmentOn,
            $existing?->createdAt,
            $existing?->updatedAt,
            $existing?->rewardAdjustmentPoints ?? 0,
            $existing?->rewardEarnedAdjustmentPoints ?? 0,
            $existing?->cargoWeightRewardPoints ?? 0,
            $assignedActorId,
            $assignedName,
        );

        return $this->repository->save($customer, $actorId, $existing !== null && $expectedUpdatedAt !== '' ? $expectedUpdatedAt : null);
    }

    public function delete(string $customerKey, string $actorId): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if ($this->find($customerKey) === null) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        $this->repository->delete($customerKey, $actorId);
    }

    /** @return list<array{type: string, occurredOn: string, summary: string, actorId: string, actorName: string, createdAt: string}> */
    public function activities(string $customerKey, int $limit = 20): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return [];
        }
        return $this->repository->activities($customerKey, max(1, min($limit, 100)));
    }

    /**
     * Logs a call, visit, email, meeting, or note, and sets the next follow-up from the same form;
     * leaving the next follow-up empty closes it.
     *
     * @param array<string, mixed> $input
     */
    public function addActivity(string $customerKey, array $input, string $actorId, string $actorName): CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if ($this->find($customerKey) === null) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        $type = strtolower($this->text($input['activity_type'] ?? '', 20));
        if (!array_key_exists($type, self::ACTIVITY_TYPES)) {
            throw new InvalidArgumentException('Select a valid activity type.');
        }
        $occurredOn = $this->date($input['occurred_on'] ?? '') ?? gmdate('Y-m-d');
        if ($occurredOn > gmdate('Y-m-d')) {
            throw new InvalidArgumentException('An activity date cannot be in the future.');
        }
        $summary = $this->multilineText($input['summary'] ?? '', 1000);
        if (strlen($summary) < 3) {
            throw new InvalidArgumentException('Describe the activity in at least a few words.');
        }
        $nextFollowUpOn = $this->date($input['next_follow_up_on'] ?? '');
        $actorName = $this->text($actorName, 160);

        return $this->repository->addActivity($customerKey, $type, $occurredOn, $summary, $nextFollowUpOn, $actorId, $actorName === '' ? 'Records user' : $actorName);
    }

    /** Clears the customer's follow-up and records a note in Activity saying it was closed. */
    public function closeFollowUp(string $customerKey, string $actorId, string $actorName): void
    {
        $customer = preg_match('/^[a-f0-9]{64}$/', $customerKey) === 1 ? $this->find($customerKey) : null;
        if ($customer === null) {
            throw new InvalidArgumentException('Customer profile not found.');
        }
        if ($customer->nextFollowUpOn === null) {
            throw new InvalidArgumentException('This customer has no follow-up to close.');
        }
        $this->addActivity($customerKey, [
            'activity_type' => 'note',
            'occurred_on' => gmdate('Y-m-d'),
            'summary' => 'Follow-up due ' . $customer->nextFollowUpOn . ' closed.',
            'next_follow_up_on' => '',
        ], $actorId, $actorName);
    }

    /**
     * Suggested merges must look like duplicates. A manual merge is an administrator's explicit choice of two
     * profiles (for example "Acme" and "Acme Logistics Cameroon"), so it skips the similarity check; both are
     * recorded the same way and can be undone from Recent merges.
     */
    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId, bool $manual = false): CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $targetCustomerKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sourceCustomerKey) !== 1
            || hash_equals($targetCustomerKey, $sourceCustomerKey)) {
            throw new InvalidArgumentException('Select two different valid customer profiles to merge.');
        }
        $target = $this->find($targetCustomerKey);
        $source = $this->find($sourceCustomerKey);
        if ($target === null || $source === null) {
            throw new InvalidArgumentException('One of the customer profiles no longer exists.');
        }
        $match = $this->duplicateMatch(
            ['normalized' => $this->normalizedDuplicateName($target->displayName), 'email' => strtolower($target->email), 'phone' => $this->phoneDigits($target->phone)],
            ['normalized' => $this->normalizedDuplicateName($source->displayName), 'email' => strtolower($source->email), 'phone' => $this->phoneDigits($source->phone)],
        );
        if ($match === null && !$manual) {
            throw new InvalidArgumentException('These customer names are not similar enough for the duplicate merge workflow.');
        }
        return $this->repository->merge($targetCustomerKey, $sourceCustomerKey, $actorId);
    }

    public function undoMerge(string $mergeId, string $actorId): CustomerProfile
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if (preg_match('/^[1-9][0-9]{0,17}$/', $mergeId) !== 1) {
            throw new InvalidArgumentException('Select a valid merge to undo.');
        }
        return $this->repository->undoMerge((int) $mergeId, $actorId);
    }

    public function dismissMerge(string $mergeId, string $actorId): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if (preg_match('/^[1-9][0-9]{0,17}$/', $mergeId) !== 1) {
            throw new InvalidArgumentException('Select a valid merge to ignore.');
        }
        $this->repository->dismissMerge((int) $mergeId, $actorId);
    }

    /** Marks two profiles as different customers, so Possible duplicate customers stops suggesting them. */
    public function dismissDuplicate(string $firstCustomerKey, string $secondCustomerKey, string $actorId): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The customer-data actor is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $firstCustomerKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $secondCustomerKey) !== 1
            || hash_equals($firstCustomerKey, $secondCustomerKey)) {
            throw new InvalidArgumentException('Select two different valid customer profiles to ignore.');
        }
        if ($this->find($firstCustomerKey) === null || $this->find($secondCustomerKey) === null) {
            throw new InvalidArgumentException('One of the customer profiles no longer exists.');
        }
        $keys = [$firstCustomerKey, $secondCustomerKey];
        sort($keys, SORT_STRING);
        $this->repository->dismissDuplicate($keys[0], $keys[1], $actorId);
    }

    /** @param array<string, mixed> $input */
    public function updateDetailsWithoutNames(string $customerKey, array $input, string $actorId): CustomerProfile
    {
        $existing = $this->find($customerKey);
        if ($existing === null) {
            throw new InvalidArgumentException('Customer profile not found.');
        }

        $input['display_name'] = $existing->displayName;
        $input['contact_name'] = $existing->contactName;

        return $this->save($existing->customerKey, $input, $actorId);
    }

    private function text(mixed $value, int $maximumLength): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (strlen($text) > $maximumLength || $this->containsControlCharacters($text)) {
            throw new InvalidArgumentException('A customer field is invalid or exceeds its allowed length.');
        }
        return $text;
    }

    private function multilineText(mixed $value, int $maximumLength): string
    {
        $text = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
        if (strlen($text) > $maximumLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new InvalidArgumentException('Customer notes are invalid or exceed 2,000 characters.');
        }
        return $text;
    }

    private function phone(string $value, string $countryCode): string
    {
        if ($value === '') {
            return '';
        }

        $callingCode = self::COUNTRY_CALLING_CODES[$countryCode];
        $callingDigits = preg_quote(ltrim($callingCode, '+'), '/');
        $localNumber = preg_replace('/^(?:\+' . $callingDigits . '|00' . $callingDigits . ')[\s.-]*/', '', trim($value)) ?? '';
        if (preg_match('/^[0-9() .-]+$/', $localNumber) !== 1) {
            throw new InvalidArgumentException('Enter the customer phone number without the country calling code.');
        }
        $digits = preg_replace('/\D+/', '', $localNumber) ?? '';
        if (strlen($digits) !== 9) {
            throw new InvalidArgumentException('Provide a valid 9-digit Cameroon phone number.');
        }

        return $callingCode . ' ' . implode(' ', str_split($digits, 3));
    }

    private function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Provide a valid follow-up date.');
        }
        return $value;
    }

    private function containsControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function collapsedName(string $name): string
    {
        return preg_replace('/[\t ]+/', ' ', $name) ?? $name;
    }

    /**
     * Keys are derived from the name so shipment synchronization and manual entry agree, but a
     * renamed profile keeps its original key; a new customer taking that old name gets a random key.
     */
    private function newCustomerKey(string $displayName): string
    {
        $key = hash('sha256', strtolower(trim($displayName)));
        return $this->repository->find($key) === null ? $key : bin2hex(random_bytes(32));
    }

    /**
     * @param array{normalized: string, email: string, phone: string} $left
     * @param array{normalized: string, email: string, phone: string} $right
     * @return array{confidence: int, reason: string}|null
     */
    private function duplicateMatch(array $left, array $right): ?array
    {
        if ($this->numbersDiffer($left['normalized'], $right['normalized'])) {
            return null;
        }
        $confidence = $this->normalizedDuplicateConfidence($left['normalized'], $right['normalized']);
        if ($confidence >= self::DUPLICATE_CONFIDENCE_THRESHOLD) {
            return ['confidence' => $confidence, 'reason' => 'name'];
        }
        if ($left['email'] !== '' && $left['email'] === $right['email']) {
            return ['confidence' => max($confidence, 90), 'reason' => 'email'];
        }
        if ($left['phone'] !== '' && $left['phone'] === $right['phone']) {
            return ['confidence' => max($confidence, 85), 'reason' => 'phone'];
        }
        return null;
    }

    /** Names that differ in any number are treated as distinct branches or sites, e.g. "Douala 1" and "Douala 2". */
    private function numbersDiffer(string $left, string $right): bool
    {
        preg_match_all('/\d+/', $left, $leftNumbers);
        preg_match_all('/\d+/', $right, $rightNumbers);
        return $leftNumbers[0] !== $rightNumbers[0];
    }

    private function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    private function normalizedDuplicateConfidence(string $left, string $right): int
    {
        if ($left === '' || $right === '' || $left === $right) {
            return $left !== '' && $left === $right ? 100 : 0;
        }
        if ($this->numbersDiffer($left, $right)) {
            return 0;
        }
        $maximumLength = max(strlen($left), strlen($right));
        if ($maximumLength < 5) {
            return 0;
        }
        $distance = levenshtein($left, $right);
        $distanceLimit = $maximumLength >= 18 ? 3 : ($maximumLength >= 9 ? 2 : 1);
        similar_text($left, $right, $similarity);
        if ($distance > $distanceLimit && $similarity < 90.0) {
            return 0;
        }
        return max(0, min(99, (int) round(max($similarity, (1 - ($distance / $maximumLength)) * 100))));
    }

    private function normalizedDuplicateName(string $name): string
    {
        $name = trim($name);
        // Fold accents the way the database collation does, so "Société" and "Societe" compare as equal.
        if (class_exists(\Normalizer::class)) {
            $name = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_D)) ?? $name;
        } else {
            $name = strtr($name, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A',
                'ç' => 'c', 'Ç' => 'C', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
                'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Î' => 'I', 'Ï' => 'I', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'Ô' => 'O', 'Ö' => 'O',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ÿ' => 'y', 'ñ' => 'n', 'Ñ' => 'N',
            ]);
        }
        $name = strtolower($name);
        $name = str_replace(['œ', 'Œ', 'æ', 'Æ'], ['oe', 'oe', 'ae', 'ae'], $name);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
        $tokens = array_values(array_filter(explode(' ', trim($name))));
        $aliases = ['co' => 'company', 'corp' => 'corporation', 'intl' => 'international', 'ltd' => 'limited'];
        $tokens = array_map(static fn (string $token): string => $aliases[$token] ?? $token, $tokens);
        return implode(' ', $tokens);
    }

    /** @return array{CustomerProfile, CustomerProfile} */
    private function preferredProfile(CustomerProfile $left, CustomerProfile $right): array
    {
        $score = static fn (CustomerProfile $profile): int => ($profile->shipmentCount * 100)
            + ($profile->contactName !== '' ? 10 : 0)
            + ($profile->email !== '' ? 5 : 0)
            + ($profile->phone !== '' ? 5 : 0)
            + ($profile->address !== '' ? 2 : 0)
            + ($profile->notes !== '' ? 1 : 0);
        return $score($right) > $score($left) ? [$right, $left] : [$left, $right];
    }

    /** @return array{items: array<never>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    private function emptyPage(int $perPage): array
    {
        return [
            'items' => [],
            'page' => 1,
            'perPage' => max(1, min($perPage, 50)),
            'totalRecords' => 0,
            'totalPages' => 1,
        ];
    }
}
