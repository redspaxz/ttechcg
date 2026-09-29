<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Application;

use InvalidArgumentException;

/**
 * Raised when AWB numbers on a sheet are already on other active sheets within the reuse window.
 * The operator can confirm the listed numbers were reissued by DHL and save again.
 */
final class AwbReuseException extends InvalidArgumentException
{
    /**
     * @param list<array{awbNumber: string, referenceNumber: string, collectionDate: string}> $conflicts
     */
    public function __construct(public readonly array $conflicts, public readonly int $windowDays)
    {
        $numbers = array_values(array_unique(array_map(static fn (array $conflict): string => $conflict['awbNumber'], $conflicts)));
        parent::__construct(sprintf(
            '%s %s already on another pickup sheet from the last %d days. Check for a typing mistake, or confirm that DHL reissued %s and save again.',
            count($numbers) === 1 ? 'AWB ' . $numbers[0] : 'AWBs ' . implode(', ', $numbers),
            count($numbers) === 1 ? 'is' : 'are',
            $windowDays,
            count($numbers) === 1 ? 'it' : 'them',
        ));
    }

    /** @return list<string> */
    public function awbNumbers(): array
    {
        return array_values(array_unique(array_map(static fn (array $conflict): string => $conflict['awbNumber'], $this->conflicts)));
    }
}
