<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class CloudflareRequestTrust
{
    /**
     * Published Cloudflare edge networks. Review against the official lists
     * during each security review: https://www.cloudflare.com/ips/
     *
     * @var list<string>
     */
    private const NETWORKS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function contains(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (self::networks() as $network) {
            if (self::matches($address, $network)) {
                return true;
            }
        }
        return false;
    }

    private static function matches(string $address, string $network): bool
    {
        if (substr_count($network, '/') !== 1) {
            return false;
        }
        [$networkAddress, $prefixValue] = explode('/', $network, 2);
        $packedAddress = inet_pton($address);
        $packedNetwork = inet_pton($networkAddress);
        if (!is_string($packedAddress) || !is_string($packedNetwork)
            || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefixValue;
        $maximumBits = strlen($packedAddress) * 8;
        if ($prefix < 0 || $prefix > $maximumBits) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /** @return list<string> */
    private static function networks(): array
    {
        $networks = self::NETWORKS;
        $configured = getenv('CLOUDFLARE_TRUSTED_PROXY_CIDRS');
        if (!is_string($configured) || trim($configured) === '') {
            return $networks;
        }

        $candidates = preg_split('/\s*,\s*/', trim($configured), 33) ?: [];
        foreach (array_slice($candidates, 0, 32) as $candidate) {
            $candidate = trim($candidate);
            if (substr_count($candidate, '/') !== 1) {
                continue;
            }
            [$address, $prefix] = explode('/', $candidate, 2);
            $packed = inet_pton($address);
            $maximumBits = is_string($packed) ? strlen($packed) * 8 : 0;
            if ($maximumBits > 0 && ctype_digit($prefix) && (int) $prefix <= $maximumBits) {
                $networks[] = $candidate;
            }
        }
        return array_values(array_unique($networks));
    }
}
