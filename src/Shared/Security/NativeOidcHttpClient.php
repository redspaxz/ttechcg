<?php

declare(strict_types=1);

namespace App\Shared\Security;

use JsonException;
use RuntimeException;

final class NativeOidcHttpClient implements OidcHttpClient
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->request($url, array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $headers), http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->request($url, array_merge(['Accept' => 'application/json'], $headers));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function request(string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Secure identity transport is unavailable.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('Invalid identity request metadata.');
            }
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Secure identity transport is unavailable.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'TTechCG-Pickupsheet-OIDC/1.0',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($response)
            || $status < 200
            || $status >= 300
            || strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('The identity provider did not return a valid response.');
        }

        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The identity provider returned malformed data.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('The identity provider returned malformed data.');
        }

        return $decoded;
    }
}
