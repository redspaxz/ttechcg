<?php

declare(strict_types=1);

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Domain\BackupRepository;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class BackupService
{
    public const FORMAT = 'ttechcg-pickupsheet-encrypted-backup';
    public const VERSION = 1;
    public const MAX_BACKUP_BYTES = 12_582_912;
    private const PAYLOAD_FORMAT = 'ttechcg-pickupsheet-data';
    private const KDF_ITERATIONS = 210000;
    private const AAD = self::FORMAT . ':1';

    public function __construct(private readonly BackupRepository $repository)
    {
    }

    /** @return array{contents: string, filename: string, tableCount: int, rowCount: int} */
    public function createEncrypted(string $passphrase): array
    {
        $passphrase = $this->passphrase($passphrase);
        $this->requireOpenSsl();
        $tables = $this->repository->exportTables();
        $rowCount = array_sum(array_map(
            static fn (array $table): int => count($table['rows'] ?? []),
            $tables,
        ));

        try {
            $plaintext = json_encode([
                'format' => self::PAYLOAD_FORMAT,
                'version' => self::VERSION,
                'createdAt' => gmdate(DATE_ATOM),
                'tables' => $tables,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup data could not be encoded.', 0, $exception);
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::KDF_ITERATIONS, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }

        try {
            $contents = json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'cipher' => 'AES-256-GCM',
                'kdf' => 'PBKDF2-SHA256',
                'iterations' => self::KDF_ITERATIONS,
                'salt' => base64_encode($salt),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'payload' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Encrypted backup could not be encoded.', 0, $exception);
        }

        if (strlen($contents) > self::MAX_BACKUP_BYTES) {
            throw new RuntimeException('The encrypted backup exceeds the 12 MB application limit.');
        }

        return [
            'contents' => $contents,
            'filename' => 'ttechcg-pickupsheet-backup-' . gmdate('Ymd-His') . '.json',
            'tableCount' => count($tables),
            'rowCount' => $rowCount,
        ];
    }

    /** @return array{tableCount: int, rowCount: int, rowsByTable: array<string, int>} */
    public function restoreEncrypted(string $contents, string $passphrase): array
    {
        $passphrase = $this->passphrase($passphrase);
        $this->requireOpenSsl();
        if ($contents === '' || strlen($contents) > self::MAX_BACKUP_BYTES) {
            throw new InvalidArgumentException('Select a valid encrypted backup file no larger than 12 MB.');
        }

        try {
            $envelope = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.', 0, $exception);
        }
        if (!is_array($envelope)
            || ($envelope['format'] ?? null) !== self::FORMAT
            || ($envelope['version'] ?? null) !== self::VERSION
            || ($envelope['cipher'] ?? null) !== 'AES-256-GCM'
            || ($envelope['kdf'] ?? null) !== 'PBKDF2-SHA256'
            || ($envelope['iterations'] ?? null) !== self::KDF_ITERATIONS
        ) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.');
        }

        $salt = $this->decode((string) ($envelope['salt'] ?? ''), 16);
        $iv = $this->decode((string) ($envelope['iv'] ?? ''), 12);
        $tag = $this->decode((string) ($envelope['tag'] ?? ''), 16);
        $ciphertext = $this->decode((string) ($envelope['payload'] ?? ''), null);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::KDF_ITERATIONS, 32, true);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
        );
        if (!is_string($plaintext)) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.');
        }

        try {
            $payload = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.', 0, $exception);
        }
        if (!is_array($payload)
            || ($payload['format'] ?? null) !== self::PAYLOAD_FORMAT
            || ($payload['version'] ?? null) !== self::VERSION
            || !is_array($payload['tables'] ?? null)
        ) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.');
        }

        $rowsByTable = $this->repository->restoreTables($payload['tables']);
        return [
            'tableCount' => count($rowsByTable),
            'rowCount' => array_sum($rowsByTable),
            'rowsByTable' => $rowsByTable,
        ];
    }

    private function passphrase(string $passphrase): string
    {
        if (strlen($passphrase) < 16 || strlen($passphrase) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $passphrase) === 1
        ) {
            throw new InvalidArgumentException('Use a backup passphrase between 16 and 200 characters.');
        }
        return $passphrase;
    }

    private function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM backup encryption is unavailable on this server.');
        }
    }

    private function decode(string $value, ?int $expectedLength): string
    {
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || $decoded === '' || ($expectedLength !== null && strlen($decoded) !== $expectedLength)) {
            throw new InvalidArgumentException('The backup file or passphrase is invalid.');
        }
        return $decoded;
    }
}
