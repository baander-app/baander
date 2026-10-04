<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\Swoole\SeekSignalBroker;
use PHPUnit\Framework\TestCase;

final class SeekSignalBrokerTest extends TestCase
{
    public function testNativeChannelReturnsTheLatestPendingSignalForItsJob(): void
    {
        \Swoole\Coroutine\run(function (): void {
            $broker = new SeekSignalBroker();
            $job = Uuid::generate();
            $otherJob = Uuid::generate();
            $broker->open($job);
            $broker->open($otherJob);

            try {
                $broker->signal($job, 10.0, 'seek');
                $broker->signal($job, 25.0, 'pause');
                $broker->signal($otherJob, 3.0, 'resume');

                self::assertSame(['position' => 25.0, 'action' => 'pause'], $broker->waitForSignal($job));
                self::assertSame(['position' => 3.0, 'action' => 'resume'], $broker->waitForSignal($otherJob));
                self::assertNull($broker->waitForSignal($job, 0.01));
            } finally {
                $broker->close($job);
                $broker->close($otherJob);
            }
        });
    }

    public function testClosedAndUnknownJobsDoNotReceiveSignals(): void
    {
        \Swoole\Coroutine\run(function (): void {
            $broker = new SeekSignalBroker();
            $job = Uuid::generate();
            self::assertNull($broker->waitForSignal($job));
            $broker->open($job);
            $broker->close($job);

            $broker->signal($job, 3.0, 'seek');

            self::assertNull($broker->waitForSignal($job));
        });
    }
}
