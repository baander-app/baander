<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\CancellableJobInterface;
use App\Shared\Application\JobCancelledException;
use App\Shared\Infrastructure\Messenger\JobCancellationCheckpoint;
use App\Shared\Infrastructure\Messenger\JobExecutionContext;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class JobCancellationCheckpointTest extends TestCase
{
    public function testOutsideAJobTheCheckpointDoesNothing(): void
    {
        $flags = $this->createMock(CancellableJobInterface::class);
        $flags->expects($this->never())->method('checkCancellation');

        (new JobCancellationCheckpoint(new JobExecutionContext(), $flags))->check();
    }

    public function testInsideAJobTheCheckpointStopsItWhenItWasCancelled(): void
    {
        $context = new JobExecutionContext();
        $checkpoint = new JobCancellationCheckpoint($context, self::flags(['job-cancelled']));

        $context->run('job-running', $checkpoint->check(...));
        try {
            $context->run('job-cancelled', $checkpoint->check(...));
            self::fail('The cancelled job must stop at its checkpoint.');
        } catch (JobCancelledException $exception) {
            self::assertStringContainsString('job-cancelled', $exception->getMessage());
        }

        self::assertNull($context->currentJobId(), 'A job that ends, or stops, leaves no current job behind.');
    }

    public function testANestedRunRestoresTheOuterJob(): void
    {
        $context = new JobExecutionContext();

        $seen = $context->run('outer', static function () use ($context): array {
            $inner = $context->run('inner', $context->currentJobId(...));

            return [$inner, $context->currentJobId()];
        });

        self::assertSame(['inner', 'outer'], $seen);
        self::assertNull($context->currentJobId());
    }

    #[RequiresPhpExtension('swoole')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCancellingOneOfTwoConcurrentCoroutineJobsDoesNotStopTheOther(): void
    {
        $context = new JobExecutionContext();
        $checkpoint = new JobCancellationCheckpoint($context, self::flags(['job-a']));
        $items = ['job-a' => 0, 'job-b' => 0];
        $outcome = [];

        \Swoole\Coroutine\run(static function () use ($context, $checkpoint, &$items, &$outcome): void {
            foreach (['job-a', 'job-b'] as $jobId) {
                \Swoole\Coroutine::create(static function () use ($jobId, $context, $checkpoint, &$items, &$outcome): void {
                    try {
                        $context->run($jobId, static function () use ($jobId, $checkpoint, &$items): void {
                            for ($item = 0; $item < 3; ++$item) {
                                // Yield, so the other job runs between this job's items.
                                \Swoole\Coroutine::sleep(0.001);
                                $checkpoint->check();
                                ++$items[$jobId];
                            }
                        });
                        $outcome[$jobId] = 'finished';
                    } catch (JobCancelledException) {
                        $outcome[$jobId] = 'cancelled';
                    }
                });
            }
        });

        self::assertSame(['job-a' => 'cancelled', 'job-b' => 'finished'], $outcome);
        self::assertSame(['job-a' => 0, 'job-b' => 3], $items);
        self::assertNull($context->currentJobId());
    }

    /** @param list<string> $cancelled */
    private static function flags(array $cancelled): CancellableJobInterface
    {
        return new readonly class($cancelled) implements CancellableJobInterface {
            /** @param list<string> $cancelled */
            public function __construct(private array $cancelled)
            {
            }

            public function checkCancellation(string $jobId): void
            {
                if (in_array($jobId, $this->cancelled, true)) {
                    throw JobCancelledException::forJob($jobId);
                }
            }
        };
    }
}
