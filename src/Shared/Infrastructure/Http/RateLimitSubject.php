<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Folds a client address into the subject a rate limiter counts it under.
 *
 * An IPv6 client is normally assigned a whole /64 and can send from any address in it,
 * so an IPv6 address counts as its /64. An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`)
 * counts as its IPv4 address, and an RFC 4007 zone suffix (`%eth0`) is dropped. Anything
 * that is not an IP address counts as one shared `unknown` subject.
 */
final class RateLimitSubject
{
    private const string UNKNOWN = 'unknown';

    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /** The subject of one client: an IPv4 address on its own, an IPv6 address as its /64. */
    public static function ofAddress(?string $address): string
    {
        return self::fold($address, ipv4HostBytes: 0);
    }

    /** The subject of a client's network: the /24 of an IPv4 address, the /64 of an IPv6 address. */
    public static function ofNetwork(?string $address): string
    {
        return self::fold($address, ipv4HostBytes: 1);
    }

    /** @param int<0, 4> $ipv4HostBytes */
    private static function fold(?string $address, int $ipv4HostBytes): string
    {
        $ip = self::ipAddress($address);

        return $ip === null
            ? self::UNKNOWN
            : IpUtils::anonymize($ip, v4Bytes: $ipv4HostBytes, v6Bytes: 8);
    }

    private static function ipAddress(?string $address): ?string
    {
        $ip = explode('%', $address ?? '', 2)[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        return str_starts_with($packed, self::IPV4_MAPPED_PREFIX)
            ? (string) inet_ntop(substr($packed, 12))
            : $ip;
    }
}
