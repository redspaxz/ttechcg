<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain;

interface InquiryRepository
{
    public function create(Inquiry $inquiry): Inquiry;
}

