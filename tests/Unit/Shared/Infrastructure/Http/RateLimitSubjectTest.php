<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\RateLimitSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitSubjectTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function addressSubjects(): iterable
    {
        yield 'IPv4 address' => ['198.18.20.7', '198.18.20.7'];
        yield 'IPv6 address' => ['2001:db8:20:1::1', '2001:db8:20:1::'];
        yield 'last address of an IPv6 /64' => ['2001:db8:20:1:ffff:ffff:ffff:ffff', '2001:db8:20:1::'];
        yield 'upper-case IPv6 address' => ['2001:DB8:20:1::1', '2001:db8:20:1::'];
        yield 'IPv4-mapped IPv6 address' => ['::ffff:198.18.20.7', '198.18.20.7'];
        yield 'link-local address with a zone' => ['fe80::1%eth0', 'fe80::'];
        yield 'IPv6 loopback' => ['::1', '::1'];
        yield 'no address' => [null, 'unknown'];
        yield 'empty address' => ['', 'unknown'];
        yield 'zone without an address' => ['%eth0', 'unknown'];
        yield 'host name' => ['client.baander.app', 'unknown'];
    }

    #[DataProvider('addressSubjects')]
    public function testAnAddressCountsAsItselfOrItsIpv6Network(?string $address, string $subject): void
    {
        self::assertSame($subject, RateLimitSubject::ofAddress($address));
    }

    /** @return iterable<string, array{string, string}> */
    public static function oneClient(): iterable
    {
        yield 'one IPv6 /64' => ['2001:db8:20:1::1', '2001:db8:20:1:ffff:ffff:ffff:ffff'];
        yield 'IPv4 and its mapped form' => ['198.18.20.7', '::ffff:198.18.20.7'];
        yield 'one address on two zones' => ['fe80::1%eth0', 'fe80::1%eth1'];
    }

    #[DataProvider('oneClient')]
    public function testAddressesOfOneClientShareASubject(string $first, string $second): void
    {
        self::assertSame(RateLimitSubject::ofAddress($first), RateLimitSubject::ofAddress($second));
    }

    /** @return iterable<string, array{string, string}> */
    public static function twoClients(): iterable
    {
        yield 'neighbouring IPv6 /64 networks' => ['2001:db8:20:1::1', '2001:db8:20:2::1'];
        yield 'two IPv4 addresses in one /24' => ['198.18.20.7', '198.18.20.8'];
    }

    #[DataProvider('twoClients')]
    public function testAddressesOfTwoClientsKeepTheirOwnSubjects(string $first, string $second): void
    {
        self::assertNotSame(RateLimitSubject::ofAddress($first), RateLimitSubject::ofAddress($second));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function networkSubjects(): iterable
    {
        yield 'IPv4 address' => ['198.18.20.7', '198.18.20.0'];
        yield 'IPv4-mapped IPv6 address' => ['::ffff:198.18.20.7', '198.18.20.0'];
        yield 'IPv6 address' => ['2001:db8:20:1::1', '2001:db8:20:1::'];
        yield 'link-local address with a zone' => ['fe80::1%eth0', 'fe80::'];
        yield 'no address' => [null, 'unknown'];
        yield 'host name' => ['client.baander.app', 'unknown'];
    }

    #[DataProvider('networkSubjects')]
    public function testANetworkIsTheIpv4Slash24OrTheIpv6Slash64(?string $address, string $subject): void
    {
        self::assertSame($subject, RateLimitSubject::ofNetwork($address));
    }
}
