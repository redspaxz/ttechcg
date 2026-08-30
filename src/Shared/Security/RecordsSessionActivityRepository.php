<?php

declare(strict_types=1);

namespace App\Shared\Security;

interface RecordsSessionActivityRepository
{
    public function start(string $activityId, RecordsPrincipal $principal, int $timestamp): void;

    public function touch(string $activityId, int $timestamp): void;

    public function finish(string $activityId, int $timestamp): void;

    /**
     * @return array{
     *     items: list<array{
     *         username: string,
     *         fullName: string,
     *         role: string,
     *         identityProvider: string,
     *         loginCount: int,
     *         totalSessionSeconds: int,
     *         averageSessionSeconds: int,
     *         lastLoginAt: string,
     *         activeNow: bool
     *     }>,
     *     totalRecords: int,
     *     activeRecords: int
     * }
     */
    public function paginatedSummary(int $days, int $limit, int $offset): array;

    /**
     * @return list<array{
     *     username: string,
     *     fullName: string,
     *     role: string,
     *     identityProvider: string,
     *     loginCount: int,
     *     totalSessionSeconds: int,
     *     averageSessionSeconds: int,
     *     lastLoginAt: string,
     *     activeNow: bool
     * }>
     */
    public function summary(int $days, int $limit): array;
}
