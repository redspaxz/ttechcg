<?php

declare(strict_types=1);

namespace App\Modules\Contact\Infrastructure;

use App\Modules\Contact\Domain\Inquiry;
use App\Modules\Contact\Domain\InquiryRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MysqlInquiryRepository implements InquiryRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(Inquiry $inquiry): Inquiry
    {
        $statement = $this->connection->prepare(
            'INSERT INTO inquiries (name, email, company, service, message, privacy_consent_at, privacy_notice_version, created_at)
             VALUES (:name, :email, :company, :service, :message, :privacy_consent_at, :privacy_notice_version, :created_at)',
        );
        $statement->execute([
            'name' => $inquiry->name,
            'email' => $inquiry->email,
            'company' => $inquiry->company !== '' ? $inquiry->company : null,
            'service' => $inquiry->service,
            'message' => $inquiry->message,
            'privacy_consent_at' => (new DateTimeImmutable($inquiry->privacyConsentAt))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            'privacy_notice_version' => $inquiry->privacyNoticeVersion,
            'created_at' => (new DateTimeImmutable($inquiry->createdAt))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
        ]);

        return new Inquiry(
            (int) $this->connection->lastInsertId(),
            $inquiry->name,
            $inquiry->email,
            $inquiry->company,
            $inquiry->service,
            $inquiry->message,
            $inquiry->privacyConsentAt,
            $inquiry->privacyNoticeVersion,
            $inquiry->createdAt,
        );
    }
}

