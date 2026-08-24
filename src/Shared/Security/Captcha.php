<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class Captcha
{
    private const SESSION_KEY_PREFIX = '_captcha_challenge_';
    private const TTL_SECONDS = 900;

    public function __construct(private readonly string $context = 'default')
    {
    }

    /** @return array{question: string, nonce: string} */
    public function issue(): array
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $addition = random_int(0, 1) === 1;

        if ($addition) {
            $question = $left . ' + ' . $right;
            $answer = $left + $right;
        } else {
            $minuend = $left + $right;
            $question = $minuend . ' − ' . $right;
            $answer = $left;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION[$this->sessionKey()] = [
            'nonce' => $nonce,
            'answer' => (string) $answer,
            'expires_at' => time() + self::TTL_SECONDS,
        ];

        return ['question' => $question, 'nonce' => $nonce];
    }

    public function validate(string $nonce, string $answer): bool
    {
        $sessionKey = $this->sessionKey();
        $challenge = $_SESSION[$sessionKey] ?? null;
        unset($_SESSION[$sessionKey]);

        if (!is_array($challenge)
            || !is_string($challenge['nonce'] ?? null)
            || !is_string($challenge['answer'] ?? null)
            || !is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < time()
        ) {
            return false;
        }

        return $nonce !== ''
            && $answer !== ''
            && hash_equals($challenge['nonce'], $nonce)
            && hash_equals($challenge['answer'], trim($answer));
    }

    private function sessionKey(): string
    {
        return self::SESSION_KEY_PREFIX . substr(hash('sha256', $this->context), 0, 12);
    }
}
