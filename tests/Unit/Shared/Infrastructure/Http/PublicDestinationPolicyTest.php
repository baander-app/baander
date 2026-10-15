<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\UnsafeDestinationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicDestinationPolicyTest extends TestCase
{
    #[DataProvider('forbiddenAddresses')]
    public function testEveryDnsAnswerMustBePublic(string $ip): void
    {
        $policy = new PublicDestinationPolicy(static fn (): array => ['1.1.1.1', $ip]);
        $this->expectException(UnsafeDestinationException::class);
        $policy->resolve('https://api.baander.app/key', ['api.baander.app']);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenAddresses(): iterable
    {
        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1',
            '0.0.0.0', '224.0.0.1', '240.0.0.1', '192.0.2.1', '::', '::1', 'fe80::1', 'fc00::1', 'ff02::1',
            '2001:db8::1', '::ffff:127.0.0.1', '::ffff:7f00:1', '64:ff9b::7f00:1', '64:ff9b:1::1',
            '2002:7f00:1::', '2001::1', 'not-an-address'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('forbiddenUrls')]
    public function testUnsafeUrlNeverResolvesDns(string $url): void
    {
        $policy = new PublicDestinationPolicy(static function (): array {
            self::fail('Rejected URL reached DNS.');
        });
        $this->expectException(UnsafeDestinationException::class);
        $policy->resolve($url, ['api.baander.app']);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenUrls(): iterable
    {
        foreach (['http://api.baander.app/x', 'https://api.baander.app:444/x', 'https://user:secret@api.baander.app/x',
            'https://api.baander.app/x#fragment', 'https://api.baander.app./x', 'https://api.baander.app.evil.baander.app/x',
            'https://api.baander.app\\@private.baander.app/x', 'https://127.0.0.1/x', 'https://[::1]/x',
            'https://2130706433/x', "https://api.baander.app/x\r\nHost: private.baander.app", 'https://api.baander.app/x y'] as $url) {
            yield $url => [$url];
        }
    }

    public function testPublicAddressesAreDeduplicatedAndMappedIpv4Normalized(): void
    {
        $policy = new PublicDestinationPolicy(static fn (): array => ['1.1.1.1', '2606:4700:4700::1111', '::ffff:1.1.1.1']);
        self::assertSame(['host' => 'api.baander.app', 'ips' => ['1.1.1.1', '2606:4700:4700::1111']], $policy->resolve('https://API.baander.app:443/x', ['api.baander.app']));
    }

    public function testSuffixUsesDomainLabelBoundary(): void
    {
        $policy = new PublicDestinationPolicy(static fn (): array => ['1.1.1.1']);
        self::assertSame('edge.push.baander.app', $policy->resolve('https://edge.push.baander.app/x', [], ['push.baander.app'])['host']);
        self::assertSame('push.baander.app', $policy->resolve('https://push.baander.app/x', [], ['push.baander.app'])['host']);
        $this->expectException(UnsafeDestinationException::class);
        $policy->resolve('https://evilpush.baander.app/x', [], ['push.baander.app']);
    }

    public function testDnsIsCheckedAgainForEveryRequest(): void
    {
        $calls = 0;
        $policy = new PublicDestinationPolicy(static function () use (&$calls): array {
            return ++$calls === 1 ? ['1.1.1.1'] : ['127.0.0.1'];
        });
        self::assertSame(['1.1.1.1'], $policy->resolve('https://api.baander.app/x', ['api.baander.app'])['ips']);
        $this->expectException(UnsafeDestinationException::class);
        $policy->resolve('https://api.baander.app/x', ['api.baander.app']);
    }

    #[DataProvider('dnsFailures')]
    public function testDnsFailureDoesNotLeakResolverException(bool $throws): void
    {
        $policy = new PublicDestinationPolicy(static function () use ($throws): array {
            if ($throws) {
                throw new \RuntimeException('sensitive resolver detail');
            }
            return [];
        });
        try {
            $policy->resolve('https://api.baander.app/x?key=secret', ['api.baander.app']);
            self::fail('DNS failure was accepted.');
        } catch (UnsafeDestinationException $e) {
            self::assertSame('The outbound destination is not permitted.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function dnsFailures(): iterable
    {
        yield 'empty' => [false];
        yield 'exception' => [true];
    }
}
