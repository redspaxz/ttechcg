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

    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    /** @return array{items: list<CustomerProfile>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    public function paginated(string $search = '', string $status = '', int $page = 1, int $perPage = 20): array
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

    /** @return list<array{pointsDelta: int, reason: string, actorId: string, createdAt: string}> */
    public function rewardAdjustments(string $customerKey, int $limit = 20): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $customerKey) !== 1) {
            return [];
        }
        return $this->repository->rewardAdjustments($customerKey, max(1, min($limit, 50)));
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
        $contactName = $this->text($input['contact_name'] ?? '', 100);
        $email = strtolower($this->text($input['email'] ?? '', 254));
        $phone = $this->text($input['phone'] ?? '', 32);
        $address = $this->text($input['address'] ?? '', 255);
        $city = $this->text($input['city'] ?? '', 100);
        $countryCode = strtoupper($this->text($input['country_code'] ?? '', 2));
        $status = strtolower($this->text($input['status'] ?? 'active', 20));
        $notes = $this->multilineText($input['notes'] ?? '', 2000);
        $nextFollowUpOn = $this->date($input['next_follow_up_on'] ?? '');

        if ($contactName !== '' && $this->containsControlCharacters($contactName)) {
            throw new InvalidArgumentException('The contact name contains invalid characters.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Provide a valid customer email address.');
        }
        if ($phone !== '' && preg_match('/^[+0-9() .-]{7,32}$/', $phone) !== 1) {
            throw new InvalidArgumentException('Provide a valid customer phone number.');
        }
        if ($countryCode !== '' && !in_array($countryCode, ['CM', 'NG'], true)) {
            throw new InvalidArgumentException('Customer country must be Cameroon or Nigeria.');
        }
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
        );

        return $this->repository->save($customer, $actorId);
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
}
