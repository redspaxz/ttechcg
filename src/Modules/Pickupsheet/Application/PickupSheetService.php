<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Application;

use App\Modules\Pickupsheet\Domain\PickupSheet;
use App\Modules\Pickupsheet\Domain\PickupSheetRepository;
use App\Modules\Pickupsheet\Domain\PickupShipment;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PickupSheetService
{
    public const PRIVACY_NOTICE_VERSION = '2026-08-24';
    private const MAX_SHIPMENTS = 50;

    public function __construct(private readonly PickupSheetRepository $repository)
    {
    }

    /** @return list<PickupSheet> */
    public function recent(int $limit = 100): array
    {
        return $this->repository->recent(max(1, min($limit, 100)));
    }

    /** @return array{items: list<PickupSheet>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    public function paginated(int $page = 1, int $perPage = 10): array
    {
        $perPage = max(1, min($perPage, 50));
        $totalRecords = $this->repository->count();
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'items' => $this->repository->recent($perPage, ($page - 1) * $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
        ];
    }

    public function findByReference(string $referenceNumber): ?PickupSheet
    {
        $referenceNumber = strtoupper(trim($referenceNumber));
        if (!preg_match('/^PS-[0-9]{8}-(?:[A-F0-9]{16}|[A-F0-9]{32}|LEGACY-[0-9]+)$/', $referenceNumber)) {
            return null;
        }

        return $this->repository->findByReference($referenceNumber);
    }

    /** @return array{sheetCount: int, shipmentCount: int, totalCashXaf: int, latestCreatedAt: ?string} */
    public function summary(): array
    {
        return $this->repository->summary();
    }

    /** @return list<array{date: string, sheetCount: int, shipmentCount: int, totalCashXaf: int}> */
    public function activityByDay(int $days = 14): array
    {
        return $this->repository->activityByDay(max(7, min($days, 31)));
    }

    /** @return list<array{destination: string, shipmentCount: int, totalCashXaf: int}> */
    public function topDestinations(int $limit = 5): array
    {
        return $this->repository->topDestinations(max(1, min($limit, 10)));
    }

    /** @return list<array{sender: string, shipmentCount: int}> */
    public function topSenders(int $months = 12, int $limit = 10): array
    {
        return $this->repository->topSenders(
            max(1, min($months, 24)),
            max(1, min($limit, 10)),
        );
    }

    /** @return list<string> */
    public function consignorSuggestions(string $query = '', int $limit = 50): array
    {
        $query = trim($query);
        if (strlen($query) > 160) {
            throw new InvalidArgumentException('The consignor search is too long.');
        }
        $suggestions = $this->repository->consignorSuggestions($query, max(1, min($limit, 50)));
        usort($suggestions, static fn (string $left, string $right): int => strcasecmp($left, $right) ?: strcmp($left, $right));
        return $suggestions;
    }

    /** @param array<string, mixed> $input */
    public function submit(array $input): PickupSheet
    {
        return $this->repository->create($this->pickupSheetFromInput($input));
    }

    /** @param array<string, mixed> $input */
    public function update(string $referenceNumber, array $input, string $actorId): PickupSheet
    {
        $existing = $this->findByReference($referenceNumber);
        if ($existing === null) {
            throw new InvalidArgumentException('Pickup sheet not found.');
        }
        $this->validateActor($actorId);

        return $this->repository->update($this->pickupSheetFromInput($input, $existing), $actorId);
    }

    public function markPaid(string $referenceNumber, string $actorId): PickupSheet
    {
        $existing = $this->findByReference($referenceNumber);
        if ($existing === null) {
            throw new InvalidArgumentException('Pickup sheet not found.');
        }
        if ($existing->isPaid()) {
            throw new InvalidArgumentException('This pickup sheet is already marked paid.');
        }
        $this->validateActor($actorId);
        return $this->repository->markPaid($referenceNumber, $actorId);
    }

    public function delete(string $referenceNumber, string $actorId): void
    {
        if ($this->findByReference($referenceNumber) === null) {
            throw new InvalidArgumentException('Pickup sheet not found.');
        }
        $this->validateActor($actorId);
        $this->repository->delete($referenceNumber, $actorId);
    }

    /** @param array<string, mixed> $input */
    private function pickupSheetFromInput(array $input, ?PickupSheet $existing = null): PickupSheet
    {
        $agentName = $this->stringValue($input['agent_name'] ?? '');
        $collectionDate = $this->stringValue($input['collection_date'] ?? '');
        $privacyConsent = $this->stringValue($input['privacy_consent'] ?? '');

        if (strlen($agentName) < 2 || strlen($agentName) > 100) {
            throw new InvalidArgumentException('Please provide the collection agent name.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $collectionDate);
        if ($date === false || $date->format('Y-m-d') !== $collectionDate) {
            throw new InvalidArgumentException('Please provide a valid pickup-sheet date.');
        }

        if ($existing === null && $privacyConsent !== '1') {
            throw new InvalidArgumentException('Please opt in to the privacy notice before saving the pickup sheet.');
        }

        $rows = $input['shipments'] ?? [];
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Shipment rows are invalid. Please reload the form and try again.');
        }
        if (count($rows) > self::MAX_SHIPMENTS) {
            throw new InvalidArgumentException('A pickup sheet can contain no more than 50 shipments.');
        }

        $submissionInstant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $submissionTime = $submissionInstant
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('H:i');
        $shipments = [];
        $totalCashReceivedXaf = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = [
                'consignor' => $this->stringValue($row['consignor'] ?? ''),
                'awb_number' => preg_replace('/\s+/', '', $this->stringValue($row['awb_number'] ?? '')) ?? '',
                'destination' => strtoupper($this->stringValue($row['destination'] ?? '')),
                'amount' => str_replace([',', ' '], '', $this->stringValue($row['amount'] ?? '')),
                'pieces' => $this->stringValue($row['pieces'] ?? ''),
                'weight_kg' => $this->normalizeWeightInput($this->stringValue($row['weight_kg'] ?? '')),
                'checked_by' => $this->stringValue($row['checked_by'] ?? ''),
            ];

            $shipmentValues = $values;
            unset($shipmentValues['checked_by']);
            if (implode('', $shipmentValues) === '') {
                continue;
            }

            $lineNumber = count($shipments) + 1;
            $this->validateShipment($values, $lineNumber);

            $collectionTime = $submissionTime;
            if ($existing !== null) {
                $collectionTime = $this->stringValue($row['collection_time'] ?? '');
                if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $collectionTime) !== 1) {
                    throw new InvalidArgumentException('Shipment ' . $lineNumber . ': provide a valid collection time.');
                }
            }

            $amountXaf = (int) $values['amount'];
            $shipment = new PickupShipment(
                $lineNumber,
                $values['consignor'],
                $values['awb_number'],
                $values['destination'],
                $amountXaf,
                (int) $values['pieces'],
                number_format((float) $values['weight_kg'], 3, '.', ''),
                $collectionTime,
                $values['checked_by'],
            );

            $shipments[] = $shipment;
            $totalCashReceivedXaf += $amountXaf;
        }

        if ($shipments === []) {
            throw new InvalidArgumentException('Add at least one shipment before saving the pickup sheet.');
        }

        $submittedAt = $submissionInstant->format(DATE_ATOM);

        return new PickupSheet(
            $existing?->id,
            $existing?->referenceNumber ?? $this->generateReferenceNumber($collectionDate),
            $agentName,
            $collectionDate,
            $shipments,
            $totalCashReceivedXaf,
            $existing?->privacyConsentAt ?? $submittedAt,
            $existing?->privacyNoticeVersion ?? self::PRIVACY_NOTICE_VERSION,
            $existing?->createdAt ?? $submittedAt,
            $existing?->status ?? 'open',
            $existing?->paidAt,
        );
    }

    private function validateActor(string $actorId): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The records actor is invalid.');
        }
    }

    private function generateReferenceNumber(string $collectionDate): string
    {
        return sprintf(
            'PS-%s-%s',
            str_replace('-', '', $collectionDate),
            strtoupper(bin2hex(random_bytes(16))),
        );
    }

    /** @param array<string, string> $row */
    private function validateShipment(array $row, int $lineNumber): void
    {
        $prefix = 'Shipment ' . $lineNumber . ': ';

        if (strlen($row['consignor']) < 2 || strlen($row['consignor']) > 160) {
            throw new InvalidArgumentException($prefix . 'provide a consignor name.');
        }
        if (!preg_match('/^[0-9]{8,20}$/', $row['awb_number'])) {
            throw new InvalidArgumentException($prefix . 'AWB number must contain 8 to 20 digits.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $row['destination'])) {
            throw new InvalidArgumentException($prefix . 'destination must be a three-letter code.');
        }
        if (!preg_match('/^[0-9]{1,9}$/', $row['amount']) || (int) $row['amount'] < 1) {
            throw new InvalidArgumentException($prefix . 'amount must be a positive XAF value.');
        }
        if (!preg_match('/^[0-9]{1,3}$/', $row['pieces']) || (int) $row['pieces'] < 1) {
            throw new InvalidArgumentException($prefix . 'pieces must be between 1 and 999.');
        }
        if (!preg_match('/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/', $row['weight_kg'])
            || (float) $row['weight_kg'] <= 0
        ) {
            throw new InvalidArgumentException($prefix . 'weight must be a positive kilogram value with up to three decimals.');
        }

        if (strlen($row['checked_by']) < 2 || strlen($row['checked_by']) > 100) {
            throw new InvalidArgumentException($prefix . 'provide the name of the person who checked the shipment.');
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    private function normalizeWeightInput(string $weight): string
    {
        $weight = preg_replace('/\s*(?:kg)?\s*$/i', '', $weight) ?? '';
        return str_contains($weight, '.') ? $weight : str_replace(',', '.', $weight);
    }
}
