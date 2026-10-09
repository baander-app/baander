<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Notification\Application\Port\WebhookDestinationPortInterface;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;

/**
 * Answers DNS for the webhook hosts the functional tests use, so no test resolves a real name.
 *
 * `baander.app`, `old.baander.app` and `new.baander.app` resolve to a public address,
 * `private.baander.app` to a LAN address and `mixed.baander.app` to both.
 */
trait WebhookDnsStub
{
    private function stubWebhookDns(): void
    {
        static::getContainer()->set(WebhookDestinationPortInterface::class, new WebhookDestinationPolicy(
            dnsResolver: static fn (string $host): array => match ($host) {
                'baander.app', 'old.baander.app', 'new.baander.app' => ['93.184.216.34'],
                'private.baander.app' => ['192.168.1.2'],
                'mixed.baander.app' => ['93.184.216.34', '192.168.1.2'],
                default => [],
            },
        ));
    }
}
