<?php

declare(strict_types=1);

namespace Baander\HttpClient;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Judges an IP address by the host a connection to it reaches. An IPv6 address in an
 * IPv4-mapped, IPv4-translated, IPv4-compatible, NAT64 (`64:ff9b::/96`), 6to4 or Teredo form
 * carries an IPv4 address, and that address is judged in its place.
 */
final class AddressClassifier
{
    /** The first 96 bits of the forms whose last 32 bits are the IPv4 address. */
    private const array IPV4_IN_LAST_32_BITS = [
        "\0\0\0\0\0\0\0\0\0\0\xff\xff", // ::ffff:0:0/96, mapped
        "\0\0\0\0\0\0\0\0\xff\xff\0\0", // ::ffff:0:0:0/96, translated
        "\0\0\0\0\0\0\0\0\0\0\0\0",     // ::/96, compatible
        "\0\x64\xff\x9b\0\0\0\0\0\0\0\0", // 64:ff9b::/96, NAT64
    ];

    /**
     * Not globally reachable, although PHP's global-range filter accepts them. The local-use
     * NAT64 prefix is refused whole: each network chooses where in it the IPv4 address sits
     * (RFC 6052 section 2.2), so the address cannot be read back.
     */
    private const array NOT_GLOBAL = ['224.0.0.0/4', 'ff00::/8', 'fec0::/10', '64:ff9b:1::/48'];

    /**
     * The addresses a connection to $ip reaches: the IPv4 addresses an IPv6 form carries, the
     * Teredo server and client both, and otherwise $ip itself.
     *
     * @return list<string> empty when $ip is not an IP address
     */
    public static function destinations(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }
        $packed = (string) inet_pton($ip);

        return match (true) {
            strlen($packed) === 4 => [$ip],
            in_array(substr($packed, 0, 12), self::IPV4_IN_LAST_32_BITS, true) => [self::ipv4(substr($packed, 12))],
            str_starts_with($packed, "\x20\x02") => [self::ipv4(substr($packed, 2, 4))],
            // The Teredo client address is stored with every bit inverted.
            str_starts_with($packed, "\x20\x01\0\0") => [self::ipv4(substr($packed, 4, 4)), self::ipv4(~substr($packed, 12))],
            default => [$ip],
        };
    }

    /** Whether every address a connection to $ip reaches is globally reachable unicast. */
    public static function isPublic(string $ip): bool
    {
        $destinations = self::destinations($ip);

        return $destinations !== [] && array_all($destinations, self::isGlobalUnicast(...));
    }

    private static function isGlobalUnicast(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false && !IpUtils::checkIp($ip, self::NOT_GLOBAL);
    }

    private static function ipv4(string $packed): string
    {
        return (string) inet_ntop($packed);
    }
}
