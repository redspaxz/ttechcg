<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSessionActivityRepository;

final class UnavailableRecordsSessionActivityRepository implements RecordsSessionActivityRepository
{
    public function start(string $activityId, RecordsPrincipal $principal, int $timestamp): void
    {
    }

    public function touch(string $activityId, int $timestamp): void
    {
    }

    public function finish(string $activityId, int $timestamp): void
    {
    }

    public function summary(int $days, int $limit): array
    {
        return [];
    }
}
