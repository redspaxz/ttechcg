<?php

declare(strict_types=1);

namespace App\Modules\CRM\Domain;

/**
 * Storage-independent rules for folding a duplicate customer profile into a retained one.
 *
 * Profiles are passed as arrays keyed by contactName, email, phone, address, city,
 * status, nextFollowUpOn, notes, and displayName so every repository merges identically.
 */
final class CustomerMergePolicy
{
    public const CONTACT_FIELDS = [
        'contactName' => 'Contact',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'city' => 'City',
    ];
    public const MERGED_FIELDS = ['contactName', 'email', 'phone', 'address', 'city', 'status', 'nextFollowUpOn', 'notes'];
    public const NOTES_MAX_BYTES = 2000;
    private const STATUS_PRIORITY = ['inactive' => 0, 'lead' => 1, 'active' => 2, 'attention' => 3];

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @return array{contactName: string, email: string, phone: string, address: string, city: string, status: string, nextFollowUpOn: ?string, notes: string}
     */
    public static function merge(array $target, array $source): array
    {
        $merged = [];
        $conflictingDetails = [];
        foreach (self::CONTACT_FIELDS as $field => $label) {
            $targetValue = trim((string) ($target[$field] ?? ''));
            $sourceValue = trim((string) ($source[$field] ?? ''));
            $merged[$field] = $targetValue !== '' ? $targetValue : $sourceValue;
            if ($targetValue !== '' && $sourceValue !== '' && $sourceValue !== $targetValue) {
                $conflictingDetails[] = $label . ': ' . $sourceValue;
            }
        }

        $targetStatus = (string) ($target['status'] ?? 'active');
        $sourceStatus = (string) ($source['status'] ?? 'active');
        $merged['status'] = (self::STATUS_PRIORITY[$sourceStatus] ?? 0) > (self::STATUS_PRIORITY[$targetStatus] ?? 0)
            ? $sourceStatus
            : $targetStatus;

        $dates = array_values(array_filter(
            [$target['nextFollowUpOn'] ?? null, $source['nextFollowUpOn'] ?? null],
            static fn (mixed $date): bool => is_string($date) && $date !== '',
        ));
        $merged['nextFollowUpOn'] = $dates === [] ? null : min($dates);

        $notes = trim((string) ($target['notes'] ?? ''));
        $sourceNotes = trim((string) ($source['notes'] ?? ''));
        if ($sourceNotes !== '') {
            $conflictingDetails[] = 'Notes: ' . $sourceNotes;
        }
        if ($conflictingDetails !== []) {
            $notes .= ($notes === '' ? '' : "\n\n")
                . 'Merged from ' . trim((string) ($source['displayName'] ?? 'duplicate profile')) . ': '
                . implode('; ', $conflictingDetails);
        }
        $merged['notes'] = self::truncateUtf8($notes, self::NOTES_MAX_BYTES);

        return $merged;
    }

    /** Cuts to at most $maximumBytes without splitting a multibyte UTF-8 character. */
    public static function truncateUtf8(string $value, int $maximumBytes): string
    {
        if (strlen($value) <= $maximumBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return rtrim(mb_strcut($value, 0, $maximumBytes, 'UTF-8'));
        }
        $cut = substr($value, 0, $maximumBytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }
        return rtrim($cut);
    }
}
