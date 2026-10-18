<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Tests\Fixtures\Messaging\MessengerConfiguration;
use PHPUnit\Framework\TestCase;

final class MessengerRoutingTest extends TestCase
{
    public function testProductionRoutesPreserveLyricsSynchronousDispatchAndSchedulerIsolation(): void
    {
        $config = MessengerConfiguration::messenger();
        self::assertCount(15, $config['routing']);
        self::assertSame('scheduler', $config['routing'][ExecuteScheduledOccurrenceCommand::class]);
        self::assertCount(14, array_filter($config['routing'], static fn (string $queue): bool => $queue === 'async'));
        self::assertArrayNotHasKey(FetchLyricsCommand::class, $config['routing']);
        self::assertArrayNotHasKey(FailureTransportProbe::class, $config['routing']);
        $workloads = MessengerConfiguration::container()->getParameter('app.messenger.workloads');
        self::assertCount(16, $workloads);
        self::assertSame(['transport' => 'async', 'resource_class' => 'io', 'default' => false], $workloads[FetchLyricsCommand::class]);
    }

    public function testTestEnvironmentAddsOnlyTheExplicitFailureProbe(): void
    {
        $prod = MessengerConfiguration::messenger();
        $test = MessengerConfiguration::messenger('test');
        self::assertSame([FailureTransportProbe::class => 'async'], array_diff_key($test['routing'], $prod['routing']));
        self::assertSame('in-memory://', $test['transports']['async']['dsn']);
        self::assertSame('in-memory://', $test['transports']['scheduler']['dsn']);
        self::assertSame('doctrine://default', $test['transports']['failed']['dsn']);
    }

    public function testDurableReceiversKeepTheirRetryAndRetentionPolicies(): void
    {
        $transports = MessengerConfiguration::messenger()['transports'];
        self::assertSame(['async', 'scheduler', 'failed'], array_keys($transports));
        self::assertSame('%env(MESSENGER_TRANSPORT_DSN)%', $transports['async']['dsn']);
        self::assertSame('%env(REDIS_URL)%/scheduler_occurrences/baander', $transports['scheduler']['dsn']);
        foreach (['async' => 'messages', 'scheduler' => 'scheduler_occurrences'] as $name => $stream) {
            self::assertSame($stream, $transports[$name]['options']['stream']);
            self::assertSame('%env(MESSENGER_CONSUMER_NAME)%', $transports[$name]['options']['consumer']);
            self::assertSame(0, $transports[$name]['options']['stream_max_entries']);
            self::assertTrue($transports[$name]['options']['delete_after_ack']);
            self::assertSame(['max_retries' => 3, 'delay' => 1000, 'multiplier' => 2, 'max_delay' => 60000], $transports[$name]['retry_strategy']);
        }
    }
}
