<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;
use RuntimeException;

final class LocalMfaService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_CODE_COUNT = 10;
    private const TOTP_PERIOD = 30;
    private const TOTP_DIGITS = 6;
    private readonly ?string $encryptionKey;

    public function __construct(
        private readonly LocalMfaRepository $repository,
        private readonly bool $enabled,
        string $encodedEncryptionKey,
        private readonly string $issuer = 'T&Tech Pickupsheet',
    ) {
        $decodedKey = base64_decode(trim($encodedEncryptionKey), true);
        $this->encryptionKey = is_string($decodedKey) && strlen($decodedKey) === 32 ? $decodedKey : null;
    }

    public static function fromEnvironment(LocalMfaRepository $repository): self
    {
        return new self(
            $repository,
            filter_var(getenv('PICKUPSHEET_LOCAL_MFA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
            (string) (getenv('PICKUPSHEET_MFA_ENCRYPTION_KEY') ?: ''),
            trim((string) (getenv('PICKUPSHEET_MFA_ISSUER') ?: 'T&Tech Pickupsheet')),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->hasValidEncryptionKey() && $this->hasOpenSsl();
    }

    public function hasValidEncryptionKey(): bool
    {
        return $this->encryptionKey !== null;
    }

    public function hasOpenSsl(): bool
    {
        return extension_loaded('openssl');
    }

    public function isEnrolled(string $subjectId): bool
    {
        $this->assertConfigured();
        return $this->repository->find($this->subjectId($subjectId)) !== null;
    }

    /** @return array{secret: string, formattedSecret: string, otpauthUri: string} */
    public function beginEnrollment(string $username): array
    {
        $this->assertConfigured();
        $secret = $this->base32Encode(random_bytes(20));
        $label = rawurlencode($this->issuer . ':' . strtolower(trim($username)));
        $uri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($this->issuer),
            self::TOTP_DIGITS,
            self::TOTP_PERIOD,
        );
        return [
            'secret' => $secret,
            'formattedSecret' => trim(chunk_split($secret, 4, ' ')),
            'otpauthUri' => $uri,
        ];
    }

    /** @return list<string> */
    public function confirmEnrollment(
        string $subjectId,
        string $username,
        string $secret,
        string $code,
        string $actorId,
    ): array {
        $this->assertConfigured();
        $subjectId = $this->subjectId($subjectId);
        $secret = $this->secret($secret);
        if ($this->matchingTotpStep($secret, $code) === null) {
            throw new InvalidArgumentException('Enter the current six-digit code from your authenticator app.');
        }

        $recoveryCodes = [];
        $recoveryHashes = [];
        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $plain = substr($this->base32Encode(random_bytes(8)), 0, 13);
            $formatted = substr($plain, 0, 4) . '-' . substr($plain, 4, 4) . '-' . substr($plain, 8);
            $recoveryCodes[] = $formatted;
            $recoveryHashes[] = $this->recoveryHash($plain);
        }

        $this->repository->save(
            $subjectId,
            strtolower(trim($username)),
            $this->encryptSecret($secret, $subjectId),
            $recoveryHashes,
            $this->actorId($actorId),
        );
        return $recoveryCodes;
    }

    /** Returns `totp` or `recovery` when verification succeeds. */
    public function verify(string $subjectId, string $code): ?string
    {
        $this->assertConfigured();
        $subjectId = $this->subjectId($subjectId);
        $enrollment = $this->repository->find($subjectId);
        if ($enrollment === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', trim($code)));
        if (preg_match('/^\d{6}$/', $normalized) === 1) {
            $secret = $this->decryptSecret($enrollment->secretEnvelope, $subjectId);
            $step = $this->matchingTotpStep($secret, $normalized);
            return $step !== null && $this->repository->claimTotpStep($subjectId, $step) ? 'totp' : null;
        }
        if (preg_match('/^[A-Z2-7]{13}$/', $normalized) === 1
            && $this->repository->consumeRecoveryCodeHash($subjectId, $this->recoveryHash($normalized))) {
            return 'recovery';
        }
        return null;
    }

    public function reset(string $subjectId, string $actorId): bool
    {
        $this->assertConfigured();
        return $this->repository->delete($this->subjectId($subjectId), $this->actorId($actorId));
    }

    /** @param list<string> $subjectIds @return array<string, bool> */
    public function statuses(array $subjectIds): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        $normalized = [];
        foreach ($subjectIds as $subjectId) {
            $normalized[] = $this->subjectId($subjectId);
        }
        return $this->repository->statuses(array_values(array_unique($normalized)));
    }

    private function matchingTotpStep(string $secret, string $code): ?int
    {
        $normalized = (string) preg_replace('/\D+/', '', trim($code));
        if (strlen($normalized) !== self::TOTP_DIGITS) {
            return null;
        }
        $currentStep = intdiv(time(), self::TOTP_PERIOD);
        foreach ([-1, 0, 1] as $offset) {
            $step = $currentStep + $offset;
            if ($step >= 0 && hash_equals($this->totpAtStep($secret, $step), $normalized)) {
                return $step;
            }
        }
        return null;
    }

    private function totpAtStep(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($number % (10 ** self::TOTP_DIGITS)), self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private function encryptSecret(string $secret, string $subjectId): string
    {
        $key = $this->encryptionKey ?? throw new RuntimeException('Local 2FA encryption is unavailable.');
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $subjectId, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('The authenticator secret could not be encrypted.');
        }
        return (string) json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decryptSecret(string $envelope, string $subjectId): string
    {
        $key = $this->encryptionKey ?? throw new RuntimeException('Local 2FA encryption is unavailable.');
        $decoded = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            throw new RuntimeException('The authenticator enrollment is invalid.');
        }
        $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
        $tag = base64_decode((string) ($decoded['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($decoded['ciphertext'] ?? ''), true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            throw new RuntimeException('The authenticator enrollment is invalid.');
        }
        $secret = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $subjectId);
        if (!is_string($secret)) {
            throw new RuntimeException('The authenticator enrollment could not be decrypted.');
        }
        return $this->secret($secret);
    }

    private function recoveryHash(string $code): string
    {
        $key = $this->encryptionKey ?? throw new RuntimeException('Local 2FA encryption is unavailable.');
        return hash_hmac('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)), $key);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split($this->secret($value)) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);
            if ($position === false) {
                throw new InvalidArgumentException('The authenticator secret is invalid.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }

    private function secret(string $secret): string
    {
        $secret = strtoupper((string) preg_replace('/\s+/', '', trim($secret)));
        if (preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
            throw new InvalidArgumentException('The authenticator secret is invalid.');
        }
        return $secret;
    }

    private function subjectId(string $subjectId): string
    {
        $subjectId = strtolower(trim($subjectId));
        if (preg_match('/^[a-z0-9][a-z0-9:@._-]{2,127}$/', $subjectId) !== 1) {
            throw new InvalidArgumentException('The local account identity is invalid.');
        }
        return $subjectId;
    }

    private function actorId(string $actorId): string
    {
        if (preg_match('/^[a-f0-9]{24}$/', $actorId) !== 1) {
            throw new InvalidArgumentException('The 2FA settings actor is invalid.');
        }
        return $actorId;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Local 2FA is enabled but its encryption key or OpenSSL support is unavailable.');
        }
    }
}
