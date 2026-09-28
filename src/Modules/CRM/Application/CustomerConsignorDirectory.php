<?php

declare(strict_types=1);

namespace App\Modules\CRM\Application;

use App\Modules\CRM\Domain\CustomerRepository;
use App\Modules\Pickupsheet\Domain\ConsignorDirectory;

/** Offers CRM customer names, including leads with no sheets yet, as pickup-sheet consignors. */
final class CustomerConsignorDirectory implements ConsignorDirectory
{
    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    public function names(string $prefix, int $limit): array
    {
        return $this->repository->suggestions($prefix, max(1, min($limit, 50)));
    }
}
