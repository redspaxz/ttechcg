<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Domain;

/** Known sender names beyond those already on pickup sheets, such as customers added in CRM. */
interface ConsignorDirectory
{
    /** @return list<string> names starting with $prefix (all names when it is empty) */
    public function names(string $prefix, int $limit): array;
}
