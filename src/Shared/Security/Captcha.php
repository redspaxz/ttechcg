<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class Captcha
{
    private const SESSION_KEY = '_captcha_challenge';
    private const TTL_SECONDS = 900;

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
        $_SESSION[self::SESSION_KEY] = [
            'nonce' => $nonce,
            'answer' => (string) $answer,
            'expires_at' => time() + self::TTL_SECONDS,
        ];

        return ['question' => $question, 'nonce' => $nonce];
    }

    public function validate(string $nonce, string $answer): bool
    {
        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

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
}
