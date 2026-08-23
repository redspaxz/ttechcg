<?php

declare(strict_types=1);

namespace App\Modules\Contact\Infrastructure;

use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryRepository;

final class DemoInquiryRepository implements InquiryRepository
{
    private const SESSION_KEY = '_demo_inquiries';

    public function create(Inquiry $inquiry): Inquiry
    {
        $rows = $_SESSION[self::SESSION_KEY] ?? [];
        $created = new Inquiry(
            count($rows) + 1,
            $inquiry->name,
            $inquiry->email,
            $inquiry->company,
            $inquiry->service,
            $inquiry->message,
            $inquiry->createdAt,
        );
        $rows[] = $created;
        $_SESSION[self::SESSION_KEY] = $rows;

        return $created;
    }
}

