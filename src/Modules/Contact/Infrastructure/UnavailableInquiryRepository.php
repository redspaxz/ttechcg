<?php

declare(strict_types=1);

namespace App\Modules\Contact\Infrastructure;

use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryRepository;
use RuntimeException;

final class UnavailableInquiryRepository implements InquiryRepository
{
    public function create(Inquiry $inquiry): Inquiry
    {
        throw new RuntimeException('Persistent inquiry storage is unavailable.');
    }
}

