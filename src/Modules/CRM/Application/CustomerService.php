<?php

declare(strict_types=1);

namespace App\Modules\CRM\Application;

use App\Modules\CRM\Domain\CustomerProfile;
use App\Modules\CRM\Domain\CustomerRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class CustomerService
{
    private const STATUSES = ['lead', 'active', 'attention', 'inactive'];
    private const DEFAULT_COUNTRY_CODE = 'CM';
    private const COUNTRY_CALLING_CODES = ['CM' => '+237'];

    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    /** @return array{items: list<CustomerProfile>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    public function paginated(string $search = '', string $status = '', int $page = 1, int $perPage = 10): array
    {
        $this->repository->synchronizeFromShipments();
        $search = substr(trim($search), 0, 100);
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);
        $result = $this->repository->paginated($search, $status, $perPage, ($page - 1) * $perPage);
        $totalPages = max(1, (int) ceil($result['totalRecords'] / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $result = $this->repository->paginated($search, $status, $perPage, ($page - 1) * $perPage);
        }

        return [
            'items' => $result['items'],
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $result['totalRecords'],
            'totalPages' => $totalPages,
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

    /** @return list<string> */
    public function suggestions(string $query, int $limit = 12): array
    {
        $query = $this->text($query, 100);
        if ($query === '') {
            return [];
        }
        $this->repository->synchronizeFromShipments();
        return $this->repository->suggestions($query, max(1, min($limit, 20)));
    }

    /** @return list<array{primary: CustomerProfile, duplicate: CustomerProfile, confidence: int}> */
    public function duplicateSuggestions(int $limit = 8): array
    {
        $this->repository->synchronizeFromShipments();
        $profiles = $this->repository->duplicateReviewProfiles(200);
        $matches = [];
        $profileCount = count($profiles);
        for ($leftIndex = 0; $leftIndex < $profileCount; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $profileCount; $rightIndex++) {
                $confidence = $this->duplicateConfidence($profiles[$leftIndex]->displayName, $profiles[$rightIndex]->displayName);
                if ($confidence < 88) {
                    continue;
                }
                [$primary, $duplicate] = $this->preferredProfile($profiles[$leftIndex], $profiles[$rightIndex]);
                $matches[] = compact('primary', 'duplicate', 'confidence');
            }
        }
        usort($matches, static fn (array $left, array $right): int => ($right['confidence'] <=> $left['confidence'])
            ?: strcasecmp($left['primary']->displayName, $right['primary']->displayName));
        return array_slice($matches, 0, max(1, min($limit, 20)));
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

    /** @return list<array{pointsDelta: int, reason: string, actorId: string, createdAt: string}> */
    public function rewardAdjustments(string $customerKey, int $limit = 20): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return [];
        }
        return $this->repository->rewardAdjustments($customerKey, max(1, min($limit, 50)));
    }

    /** @return list<array{pointsDelta: int, reason: string, actorId: string, createdAt: string}> */
    public function rewardRedemptions(string $customerKey, int $limit = 20): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return [];
        }
        return $this->repository->rewardRedemptions($customerKey, max(1, min($limit, 50)));
    }

    /** @return array{items: list<array{pointsDelta: int, reason: string, actorId: string, createdAt: string}>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    public function paginatedRewardRedemptions(string $customerKey, int $page = 1, int $perPage = 10): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return $this->emptyPage($perPage);
        }
        $perPage = max(1, min($perPage, 50));
        $totalRecords = $this->repository->rewardRedemptionCount($customerKey);
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = max(1, min($page, $totalPages));
        return [
            'items' => $this->repository->rewardRedemptions($customerKey, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
        ];
    }

    public function adjustRewards(
        string $customerKey,
        string $operation,
        mixed $points,
        mixed $reason,
        string $actorId,
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
        if ($customer->rewardBalance() + $pointsDelta < 0) {
            throw new InvalidArgumentException('A redemption cannot exceed the available reward balance.');
        }

        return $this->repository->addRewardAdjustment(
            $customer->customerKey,
            $pointsDelta,
            $reason,
            $actorId,
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
        $displayName = preg_replace('/[\t ]+/', ' ', $displayName) ?? $displayName;
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

        $key = $existing?->customerKey ?? hash('sha256', strtolower(trim($displayName)));
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
        );

        return $this->repository->save($customer, $actorId);
    }

    public function merge(string $targetCustomerKey, string $sourceCustomerKey, string $actorId): CustomerProfile
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
        if ($this->duplicateConfidence($target->displayName, $source->displayName) < 88) {
            throw new InvalidArgumentException('These customer names are not similar enough for the duplicate merge workflow.');
        }
        return $this->repository->merge($targetCustomerKey, $sourceCustomerKey, $actorId);
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

    private function duplicateConfidence(string $left, string $right): int
    {
        $left = $this->normalizedDuplicateName($left);
        $right = $this->normalizedDuplicateName($right);
        if ($left === '' || $right === '' || $left === $right) {
            return $left !== '' && $left === $right ? 100 : 0;
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
        $name = strtolower(trim($name));
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
