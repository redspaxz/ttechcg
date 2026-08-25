<?php

declare(strict_types=1);

namespace App\Shared\Security;

use RuntimeException;

final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $enabled = true,
    ) {
    }

    /** Returns zero when allowed, otherwise the number of seconds before retrying. */
    public function consume(string $scope, string $clientIdentifier, int $limit, int $windowSeconds): int
    {
        if (!$this->enabled) {
            return 0;
        }
        if ($limit < 1 || $windowSeconds < 1) {
            throw new RuntimeException('Rate-limit policy is invalid.');
        }

        $this->ensureDirectory();
        $filename = hash('sha256', $scope . '|' . $clientIdentifier) . '.json';
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        $stream = fopen($path, 'c+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the rate-limit store.');
        }

        @chmod($path, 0600);

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the rate-limit store.');
            }

            $contents = stream_get_contents($stream);
            $state = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            $now = time();
            $windowStartedAt = is_array($state) ? (int) ($state['window_started_at'] ?? 0) : 0;
            $attempts = is_array($state) ? (int) ($state['attempts'] ?? 0) : 0;

            if ($windowStartedAt < 1 || $now - $windowStartedAt >= $windowSeconds) {
                $windowStartedAt = $now;
                $attempts = 0;
            }

            $attempts++;
            $encoded = json_encode([
                'window_started_at' => $windowStartedAt,
                'attempts' => $attempts,
            ], JSON_THROW_ON_ERROR);

            rewind($stream);
            if (!ftruncate($stream, 0) || fwrite($stream, $encoded) === false || !fflush($stream)) {
                throw new RuntimeException('Unable to update the rate-limit store.');
            }

            flock($stream, LOCK_UN);

            if ($attempts > $limit) {
                return max(1, $windowSeconds - ($now - $windowStartedAt));
            }

            return 0;
        } finally {
            fclose($stream);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the rate-limit store.');
        }

        @chmod($this->directory, 0700);
        if (!is_writable($this->directory)) {
            throw new RuntimeException('The rate-limit store is not writable.');
        }
    }
}
