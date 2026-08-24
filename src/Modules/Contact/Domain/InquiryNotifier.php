<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain;

interface InquiryNotifier
{
    public function notify(Inquiry $inquiry): bool;
}

