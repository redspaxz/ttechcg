<?php

declare(strict_types=1);

namespace App\Modules\Contact\Infrastructure;

use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryNotifier;

final class NativeMailInquiryNotifier implements InquiryNotifier
{
    public function __construct(
        private readonly string $recipient,
        private readonly string $sender = 'website@ttechcg.com',
    ) {
    }

    public function notify(Inquiry $inquiry): bool
    {
        if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)) {
            error_log('Inquiry notification recipient is not configured.');
            return false;
        }

        $subject = 'New T&Tech website inquiry: ' . $this->serviceLabel($inquiry->service);
        $sender = filter_var($this->sender, FILTER_VALIDATE_EMAIL)
            ? $this->sender
            : 'website@ttechcg.com';
        $body = implode(PHP_EOL, [
            'A new inquiry was submitted on ttechcg.com.',
            '',
            'Name: ' . $inquiry->name,
            'Email: ' . $inquiry->email,
            'Company: ' . ($inquiry->company !== '' ? $inquiry->company : 'Not provided'),
            'Service: ' . $this->serviceLabel($inquiry->service),
            'Submitted: ' . $inquiry->createdAt,
            '',
            'Message:',
            $inquiry->message,
        ]);
        $headers = implode("\r\n", [
            'From: T&Tech Website <' . $sender . '>',
            'Reply-To: ' . $inquiry->email,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        $sent = mail($this->recipient, $subject, wordwrap($body, 78), $headers);
        if (!$sent) {
            error_log('Unable to send inquiry notification email for inquiry ' . ($inquiry->id ?? 'unknown') . '.');
        }

        return $sent;
    }

    private function serviceLabel(string $service): string
    {
        return match ($service) {
            'digital-products' => 'Digital product engineering',
            'workflow-automation' => 'Workflow automation',
            'data-cloud' => 'Data & cloud systems',
            'technical-advisory' => 'Technical advisory',
            'pickupsheet' => 'Pickupsheet',
            default => 'Other',
        };
    }
}
