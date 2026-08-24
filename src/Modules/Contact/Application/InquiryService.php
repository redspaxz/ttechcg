<?php

declare(strict_types=1);

namespace App\Modules\Contact\Application;

use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryNotifier;
use App\Modules\Contact\Domain\InquiryRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class InquiryService
{
    public const PRIVACY_NOTICE_VERSION = '2026-08-24';

    private const SERVICES = [
        'network-outsourcing',
        'managed-infrastructure',
        'cloud-security',
        'business-solutions',
        'btspos',
        'digital-products',
        'workflow-automation',
        'data-cloud',
        'technical-advisory',
        'pickupsheet',
        'other',
    ];

    public function __construct(
        private readonly InquiryRepository $repository,
        private readonly ?InquiryNotifier $notifier = null,
    ) {
    }

    /** @param array<string, string> $input */
    public function submit(array $input): Inquiry
    {
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $company = trim($input['company'] ?? '');
        $service = trim($input['service'] ?? '');
        $message = trim($input['message'] ?? '');
        $privacyConsent = trim($input['privacy_consent'] ?? '');

        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Please provide your full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
            throw new InvalidArgumentException('Please provide a valid email address.');
        }
        if (strlen($company) > 140) {
            throw new InvalidArgumentException('Company name must be 140 characters or fewer.');
        }
        if (!in_array($service, self::SERVICES, true)) {
            throw new InvalidArgumentException('Please select a valid service area.');
        }
        if (strlen($message) < 20 || strlen($message) > 2000) {
            throw new InvalidArgumentException('Tell us about the opportunity in 20 to 2,000 characters.');
        }
        if ($privacyConsent !== '1') {
            throw new InvalidArgumentException('Please opt in to the privacy notice before sending your inquiry.');
        }

        $submittedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

        $created = $this->repository->create(new Inquiry(
            null,
            $name,
            strtolower($email),
            $company,
            $service,
            $message,
            $submittedAt,
            self::PRIVACY_NOTICE_VERSION,
            $submittedAt,
        ));

        if ($this->notifier !== null) {
            $this->notifier->notify($created);
        }

        return $created;
    }
}
