<?php

declare(strict_types=1);

namespace App\Modules\CRM\Application;

use App\Modules\CRM\Domain\CustomerProfile;
use InvalidArgumentException;

/** Raised when a new or renamed profile would take a name that already belongs to another profile. */
final class DuplicateCustomerException extends InvalidArgumentException
{
    public function __construct(public readonly CustomerProfile $existing, public readonly ?string $alias = null)
    {
        parent::__construct($alias === null
            ? 'A customer profile already uses this organization name.'
            : sprintf('%s was merged into %s. Use that profile instead.', $alias, $existing->displayName));
    }
}
