<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookDestinationPolicyTest extends TestCase
{
    public function testDnsAnswersAreAllValidatedAndResolvedAfresh(): void
    {
        $lookups = 0;
        $policy = new WebhookDestinationPolicy(dnsResolver: static function (string $host) use (&$lookups): array {
            self::assertSame('hooks.example', $host);
            return ++$lookups === 1 ? ['93.184.216.34', '2606:4700:4700::1111'] : ['93.184.216.34', '::ffff:127.0.0.1'];
        });
        self::assertSame(['host' => 'hooks.example', 'ips' => ['93.184.216.34', '2606:4700:4700::1111']], $policy->resolve('https://hooks.example/hook'));
        self::assertNull($policy->resolve('https://hooks.example/hook'));
        self::assertSame(2, $lookups);
    }

    public function testDnsFailureRejectsDestination(): void
    {
        $policy = new WebhookDestinationPolicy(dnsResolver: static fn (string $host): array => []);
        self::assertNull($policy->resolve('https://missing.example/hook'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach ([
            'http://169.254.169.254/hook', 'http://169.254.0.1/hook',
            'http://127.0.0.1/hook', 'http://0.0.0.0/hook', 'http://224.0.0.1/hook',
            'http://[::1]/hook', 'http://[::]/hook', 'http://[fe80::1]/hook',
            'http://[::ffff:127.0.0.1]/hook', 'http://[::FFFF:7f00:1]/hook',
            'http://192.168.1.2/hook', 'http://[fd00::1]/hook',
            'https://user:secret@1.1.1.1/hook', 'ftp://1.1.1.1/hook',
            'https://1.1.1.1/hook#fragment', 'not-a-url',
        ] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeDestinationsAreRejected(string $url): void
    {
        self::assertNull((new WebhookDestinationPolicy())->resolve($url));
    }

    public function testPublicLiteralIsPinnedWithoutDnsLookup(): void
    {
        self::assertSame(['host' => '1.1.1.1', 'ips' => ['1.1.1.1']], (new WebhookDestinationPolicy())->resolve('https://1.1.1.1/hook'));
    }

    public function testOnlyExplicitlyAllowedLanAddressesAreAccepted(): void
    {
        $policy = new WebhookDestinationPolicy(['192.168.1.2', 'fd00:1::/64']);
        self::assertNotNull($policy->resolve('http://192.168.1.2/hook'));
        self::assertNotNull($policy->resolve('http://[fd00:1::2]/hook'));
        self::assertNull($policy->resolve('http://192.168.1.3/hook'));
        self::assertNull($policy->resolve('http://[fd00:2::2]/hook'));
    }

    public function testAllowlistCannotEnableLoopbackOrMetadataEndpoints(): void
    {
        $policy = new WebhookDestinationPolicy(['0.0.0.0/0', '::/0']);
        self::assertNull($policy->resolve('http://169.254.169.254/hook'));
        self::assertNull($policy->resolve('http://127.0.0.1/hook'));
        self::assertNull($policy->resolve('http://[::1]/hook'));
    }
}
