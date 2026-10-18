<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Infrastructure\Worker\Autoscaling\QueueObservation;
use App\Shared\Infrastructure\Worker\Autoscaling\RedisQueueSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisQueueSamplerTest extends TestCase
{
    #[DataProvider('unsupportedConfigurations')]
    public function testUnsupportedOrMalformedConfigurationHasOnlyRedactedFaults(string $dsn): void
    {
        $sample = (new RedisQueueSampler($dsn))->sample(123, 1700000000);
        self::assertSame('unavailable', $sample->state);
        self::assertFalse($sample->isKnown());
        self::assertSame(123.0, $sample->sampledAt);
        self::assertSame('redis_observation_unavailable', $sample->fault);
        self::assertStringNotContainsString('secret', json_encode($sample, JSON_THROW_ON_ERROR));
        self::assertNull($sample->unreadCount);
        self::assertNull($sample->dueCount);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedConfigurations(): iterable
    {
        yield 'invalid scheme' => ['https://user:secret@baander.app/messages'];
        yield 'cluster' => ['redis://user:secret@baander.app/messages?cluster=1'];
        yield 'sentinel' => ['redis://user:secret@baander.app/messages?sentinel=master'];
        yield 'multiple endpoints' => ['redis://user:secret@baander.app,redis://baander.app'];
        yield 'oversized' => ['redis://user:secret@baander.app/' . str_repeat('a', 4096)];
        yield 'oversized stream' => ['redis://user:secret@baander.app/' . str_repeat('a', 257)];
    }

    public function testUnknownLagIsNotAnEmptyQueueEvenWhenOldestUnreadIsObserved(): void
    {
        $sample = new QueueObservation('unknown_lag', 100, pendingCount: 3, dueCount: 2, futureCount: 8, oldestUnreadAgeSeconds: 30);
        self::assertFalse($sample->isKnown());
        self::assertNull($sample->unreadCount);
        self::assertSame(30.0, $sample->oldestUnreadAgeSeconds);
        self::assertSame(2, $sample->dueCount);
        self::assertSame(8, $sample->futureCount);
    }

    public function testDsnUnlimitedTimeoutAndLazyCannotBypassTheObservationReadDeadline(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, $error);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $start = hrtime(true) / 1e9;
        try {
            // A TCP listener with no protocol response holds EVAL until its read deadline.
            $sample = (new RedisQueueSampler('redis://' . $address . '/messages?timeout=0&read_timeout=0&lazy=1&persistent_id=secret'))->sample();
        } finally {
            fclose($server);
        }
        $elapsed = hrtime(true) / 1e9 - $start;
        self::assertSame('unavailable', $sample->state);
        self::assertGreaterThanOrEqual(0.15, $elapsed);
        self::assertLessThan(1.5, $elapsed);
        self::assertLessThanOrEqual($start + 0.05, $sample->sampledAt);
    }
}
