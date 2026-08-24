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

    /** @param array<string, mixed> $input */
    public function submit(array $input): PickupSheet
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

        if ($privacyConsent !== '1') {
            throw new InvalidArgumentException('Please opt in to the privacy notice before saving the pickup sheet.');
        }

        $rows = $input['shipments'] ?? [];
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Shipment rows are invalid. Please reload the form and try again.');
        }
        if (count($rows) > self::MAX_SHIPMENTS) {
            throw new InvalidArgumentException('A pickup sheet can contain no more than 50 shipments.');
        }

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
                'collection_time' => $this->stringValue($row['collection_time'] ?? ''),
                'checked_by' => $this->stringValue($row['checked_by'] ?? ''),
            ];

            if (implode('', $values) === '') {
                continue;
            }

            $lineNumber = count($shipments) + 1;
            $this->validateShipment($values, $lineNumber);

            $amountXaf = (int) $values['amount'];
            $shipment = new PickupShipment(
                $lineNumber,
                $values['consignor'],
                $values['awb_number'],
                $values['destination'],
                $amountXaf,
                (int) $values['pieces'],
                number_format((float) $values['weight_kg'], 3, '.', ''),
                $values['collection_time'],
                $values['checked_by'],
            );

            $shipments[] = $shipment;
            $totalCashReceivedXaf += $amountXaf;
        }

        if ($shipments === []) {
            throw new InvalidArgumentException('Add at least one shipment before saving the pickup sheet.');
        }

        $submittedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

        return $this->repository->create(new PickupSheet(
            null,
            $this->generateReferenceNumber($collectionDate),
            $agentName,
            $collectionDate,
            $shipments,
            $totalCashReceivedXaf,
            $submittedAt,
            self::PRIVACY_NOTICE_VERSION,
            $submittedAt,
        ));
    }

    private function generateReferenceNumber(string $collectionDate): string
    {
        return sprintf(
            'PS-%s-%s',
            str_replace('-', '', $collectionDate),
            strtoupper(bin2hex(random_bytes(8))),
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

        $time = DateTimeImmutable::createFromFormat('!H:i', $row['collection_time']);
        if ($time === false || $time->format('H:i') !== $row['collection_time']) {
            throw new InvalidArgumentException($prefix . 'provide a valid collection time.');
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
