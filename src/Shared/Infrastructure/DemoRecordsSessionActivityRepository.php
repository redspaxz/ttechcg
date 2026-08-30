<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSessionActivityRepository;

final class DemoRecordsSessionActivityRepository implements RecordsSessionActivityRepository
{
    private const SESSION_KEY = '_demo_records_session_activity';
    private const ACTIVE_WINDOW_SECONDS = 900;

    public function start(string $activityId, RecordsPrincipal $principal, int $timestamp): void
    {
        $sessions = $this->sessions();
        $sessions[$activityId] = [
            'username' => $principal->username,
            'fullName' => $principal->fullName(),
            'role' => $principal->role,
            'identityProvider' => $principal->identityProvider,
            'loggedInAt' => $timestamp,
            'lastSeenAt' => $timestamp,
            'loggedOutAt' => null,
        ];
        $_SESSION[self::SESSION_KEY] = $sessions;
    }

    public function touch(string $activityId, int $timestamp): void
    {
        $sessions = $this->sessions();
        if (isset($sessions[$activityId]) && $sessions[$activityId]['loggedOutAt'] === null) {
            $sessions[$activityId]['lastSeenAt'] = max($sessions[$activityId]['lastSeenAt'], $timestamp);
            $_SESSION[self::SESSION_KEY] = $sessions;
        }
    }

    public function finish(string $activityId, int $timestamp): void
    {
        $sessions = $this->sessions();
        if (isset($sessions[$activityId]) && $sessions[$activityId]['loggedOutAt'] === null) {
            $finishedAt = max($sessions[$activityId]['lastSeenAt'], $timestamp);
            $sessions[$activityId]['lastSeenAt'] = $finishedAt;
            $sessions[$activityId]['loggedOutAt'] = $finishedAt;
            $_SESSION[self::SESSION_KEY] = $sessions;
        }
    }

    public function summary(int $days, int $limit): array
    {
        return $this->paginatedSummary($days, $limit, 0)['items'];
    }

    public function paginatedSummary(int $days, int $limit, int $offset): array
    {
        $now = time();
        $minimumTimestamp = $now - max(1, $days) * 86400;
        $activeAfter = $now - self::ACTIVE_WINDOW_SECONDS;
        $users = [];

        foreach ($this->sessions() as $session) {
            if ($session['loggedInAt'] < $minimumTimestamp) {
                continue;
            }

            $key = strtolower($session['username']) . '|' . $session['identityProvider'];
            $users[$key] ??= [
                'username' => $session['username'],
                'fullName' => $session['fullName'],
                'role' => $session['role'],
                'identityProvider' => $session['identityProvider'],
                'loginCount' => 0,
                'totalSessionSeconds' => 0,
                'averageSessionSeconds' => 0,
                'lastLoginAt' => '',
                'activeNow' => false,
            ];

            $durationEnd = $session['loggedOutAt'] ?? $session['lastSeenAt'];
            $users[$key]['loginCount']++;
            $users[$key]['totalSessionSeconds'] += max(0, $durationEnd - $session['loggedInAt']);
            $users[$key]['activeNow'] = $users[$key]['activeNow']
                || ($session['loggedOutAt'] === null && $session['lastSeenAt'] >= $activeAfter);
            if ($users[$key]['lastLoginAt'] === ''
                || $session['loggedInAt'] > strtotime($users[$key]['lastLoginAt'] . ' UTC')) {
                $users[$key]['fullName'] = $session['fullName'];
                $users[$key]['role'] = $session['role'];
                $users[$key]['lastLoginAt'] = gmdate('Y-m-d H:i:s', $session['loggedInAt']);
            }
        }

        foreach ($users as &$user) {
            $user['averageSessionSeconds'] = (int) round($user['totalSessionSeconds'] / max(1, $user['loginCount']));
        }
        unset($user);

        usort($users, static function (array $left, array $right): int {
            $activeOrder = (int) $right['activeNow'] <=> (int) $left['activeNow'];
            if ($activeOrder !== 0) {
                return $activeOrder;
            }
            $loginOrder = $right['loginCount'] <=> $left['loginCount'];
            return $loginOrder !== 0 ? $loginOrder : strcmp($right['lastLoginAt'], $left['lastLoginAt']);
        });

        return [
            'items' => array_slice($users, max(0, $offset), max(1, min($limit, 100))),
            'totalRecords' => count($users),
            'activeRecords' => count(array_filter($users, static fn (array $user): bool => $user['activeNow'])),
        ];
    }

    /** @return array<string, array{username: string, fullName: string, role: string, identityProvider: string, loggedInAt: int, lastSeenAt: int, loggedOutAt: ?int}> */
    private function sessions(): array
    {
        $sessions = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($sessions) ? $sessions : [];
    }
}
