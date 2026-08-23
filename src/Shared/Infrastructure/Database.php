<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    public static function connect(bool $throwOnFailure = false): ?PDO
    {
        try {
            if (!self::isConfigured()) {
                throw new RuntimeException('DB_PASSWORD or DATABASE_URL must be configured.');
            }

            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('The pdo_mysql extension is not installed.');
            }

            [$dsn, $user, $password] = self::configuration();
            $connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec("SET time_zone = '+00:00'");

            return $connection;
        } catch (PDOException | RuntimeException $exception) {
            if ($throwOnFailure) {
                throw new RuntimeException('Unable to connect to MySQL: ' . $exception->getMessage(), 0, $exception);
            }

            return null;
        }
    }

    private static function isConfigured(): bool
    {
        $databaseUrl = getenv('DATABASE_URL');
        $password = getenv('DB_PASSWORD');

        return (is_string($databaseUrl) && $databaseUrl !== '')
            || (is_string($password) && $password !== '');
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function configuration(): array
    {
        $databaseUrl = getenv('DATABASE_URL');

        if (is_string($databaseUrl) && $databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts === false || !isset($parts['host'], $parts['path'])) {
                throw new RuntimeException('DATABASE_URL is invalid.');
            }

            return [
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $parts['host'],
                    $parts['port'] ?? 3306,
                    ltrim($parts['path'], '/'),
                ),
                urldecode($parts['user'] ?? ''),
                urldecode($parts['pass'] ?? ''),
            ];
        }

        return [
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'ttecwymc_PS1',
            ),
            getenv('DB_USER') ?: 'ttecwymc_tt231',
            getenv('DB_PASSWORD') ?: '',
        ];
    }
}

