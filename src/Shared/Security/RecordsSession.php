<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Throwable;

final class RecordsSession
{
    private const SESSION_KEY = '_pickupsheet_identity';
    private const ABSOLUTE_LIFETIME = 28800;
    private const IDLE_LIFETIME = 3600;
    private const ACTIVITY_TOUCH_INTERVAL = 60;

    public function __construct(private readonly ?RecordsSessionActivityRepository $activityRepository = null)
    {
    }

    public function login(RecordsPrincipal $principal): void
    {
        $now = time();
        $this->finishActivity($now);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $activityId = bin2hex(random_bytes(16));
        $_SESSION[self::SESSION_KEY] = [
            'username' => $principal->username,
            'version' => $principal->authenticationVersion,
            'role' => $principal->role,
            'first_name' => $principal->firstName,
            'last_name' => $principal->lastName,
            'display_name' => $principal->displayName,
            'identity_provider' => $principal->identityProvider,
            'issued_at' => $now,
            'last_seen_at' => $now,
            'activity_id' => $activityId,
            'activity_recorded_at' => $now,
        ];
        $this->recordActivity(static fn (RecordsSessionActivityRepository $repository) => $repository->start(
            $activityId,
            $principal,
            $now,
        ));
    }

    public function principal(RecordsAccess $access): ?RecordsPrincipal
    {
        $identity = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($identity)) {
            return null;
        }

        $username = is_string($identity['username'] ?? null) ? $identity['username'] : '';
        $version = is_string($identity['version'] ?? null) ? $identity['version'] : '';
        $identityProvider = is_string($identity['identity_provider'] ?? null) ? $identity['identity_provider'] : 'local';
        $issuedAt = is_int($identity['issued_at'] ?? null) ? $identity['issued_at'] : 0;
        $lastSeenAt = is_int($identity['last_seen_at'] ?? null) ? $identity['last_seen_at'] : 0;
        $now = time();

        if ($username === '' || $version === '') {
            $this->forget();
            return null;
        }
        if ($issuedAt < $now - self::ABSOLUTE_LIFETIME
            || $lastSeenAt < $now - self::IDLE_LIFETIME) {
            $this->forget($lastSeenAt > 0 ? $lastSeenAt : $now);
            return null;
        }

        if (in_array($identityProvider, ['jumpcloud', 'cloudflare_access'], true)) {
            $role = is_string($identity['role'] ?? null) ? $identity['role'] : '';
            $firstName = is_string($identity['first_name'] ?? null) ? $identity['first_name'] : '';
            $lastName = is_string($identity['last_name'] ?? null) ? $identity['last_name'] : '';
            $displayName = is_string($identity['display_name'] ?? null) ? $identity['display_name'] : '';
            if (!RecordsPrincipal::isValidRole($role)
                || filter_var($username, FILTER_VALIDATE_EMAIL) === false
                || strlen($username) > 100
                || preg_match('/^[a-f0-9]{64}$/', $version) !== 1
                || !$this->validName($firstName)
                || !$this->validName($lastName)
                || !$this->validDisplayName($displayName)) {
                $this->forget();
                return null;
            }

            $_SESSION[self::SESSION_KEY]['last_seen_at'] = $now;
            $this->touchActivity($now);
            return new RecordsPrincipal($username, $role, $version, $firstName, $lastName, $identityProvider, $displayName);
        }
        if ($identityProvider !== 'local') {
            $this->forget();
            return null;
        }

        $principal = $access->resolvePrincipal($username);
        if ($principal === null || !hash_equals($version, $principal->authenticationVersion)) {
            $this->forget();
            return null;
        }

        $_SESSION[self::SESSION_KEY]['last_seen_at'] = $now;
        $this->touchActivity($now);
        return $principal;
    }

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
    public function activitySummary(int $days = 30, int $limit = 50): array
    {
        return $this->activityRepository?->summary(
            max(1, min($days, 365)),
            max(1, min($limit, 100)),
        ) ?? [];
    }

    /** @return array{items: list<array<string, mixed>>, page: int, perPage: int, totalRecords: int, totalPages: int, activeRecords: int} */
    public function paginatedActivitySummary(int $days = 30, int $page = 1, int $perPage = 10): array
    {
        $days = max(1, min($days, 365));
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);
        $result = $this->activityRepository?->paginatedSummary($days, $perPage, ($page - 1) * $perPage)
            ?? ['items' => [], 'totalRecords' => 0, 'activeRecords' => 0];
        $totalPages = max(1, (int) ceil($result['totalRecords'] / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $result = $this->activityRepository?->paginatedSummary($days, $perPage, ($page - 1) * $perPage)
                ?? ['items' => [], 'totalRecords' => 0, 'activeRecords' => 0];
        }

        return [
            'items' => $result['items'],
            'page' => $page,
            'perPage' => $perPage,
            'totalRecords' => $result['totalRecords'],
            'totalPages' => $totalPages,
            'activeRecords' => $result['activeRecords'],
        ];
    }

    public function logout(): void
    {
        $this->forget();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function forget(?int $finishedAt = null): void
    {
        $this->finishActivity($finishedAt ?? time());
        unset($_SESSION[self::SESSION_KEY]);
    }

    private function touchActivity(int $timestamp): void
    {
        $identity = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($identity)) {
            return;
        }
        $activityId = is_string($identity['activity_id'] ?? null) ? $identity['activity_id'] : '';
        $recordedAt = is_int($identity['activity_recorded_at'] ?? null) ? $identity['activity_recorded_at'] : 0;
        if (preg_match('/^[a-f0-9]{32}$/', $activityId) !== 1
            || $recordedAt > $timestamp - self::ACTIVITY_TOUCH_INTERVAL) {
            return;
        }

        $_SESSION[self::SESSION_KEY]['activity_recorded_at'] = $timestamp;
        $this->recordActivity(static fn (RecordsSessionActivityRepository $repository) => $repository->touch(
            $activityId,
            $timestamp,
        ));
    }

    private function finishActivity(int $timestamp): void
    {
        $identity = $_SESSION[self::SESSION_KEY] ?? null;
        $activityId = is_array($identity) && is_string($identity['activity_id'] ?? null)
            ? $identity['activity_id']
            : '';
        if (preg_match('/^[a-f0-9]{32}$/', $activityId) !== 1) {
            return;
        }

        $this->recordActivity(static fn (RecordsSessionActivityRepository $repository) => $repository->finish(
            $activityId,
            $timestamp,
        ));
    }

    /** @param callable(RecordsSessionActivityRepository): void $operation */
    private function recordActivity(callable $operation): void
    {
        if ($this->activityRepository === null) {
            return;
        }

        try {
            $operation($this->activityRepository);
        } catch (Throwable $exception) {
            error_log('Pickupsheet session activity could not be recorded: ' . $exception->getMessage());
        }
    }

    private function validName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,48}$/u", $name) === 1;
    }

    private function validDisplayName(string $name): bool
    {
        return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}-]{0,98}$/u", $name) === 1;
    }
}
