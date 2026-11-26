<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\NativeExecutionAttempt;
use App\Shared\Infrastructure\Worker\NativeExecutionProtocol;
use App\Shared\Infrastructure\Worker\OutputTail;
use PHPUnit\Framework\TestCase;

/** Output past its bound fails one attempt; error output keeps only its newest bytes. */
final class NativeExecutionAttemptTest extends TestCase
{
    /** @var resource */
    private $process;

    protected function setUp(): void
    {
        $process = fopen('php://memory', 'rb');
        self::assertIsResource($process);
        $this->process = $process;
    }

    protected function tearDown(): void
    {
        fclose($this->process);
    }

    public function testOutputPastItsBoundAsksOnceForTheCommandToStopAndKeepsNoneOfIt(): void
    {
        $attempt = $this->newAttempt();

        $attempt->collect(1, str_repeat('o', NativeExecutionProtocol::OUTPUT_BYTES)) |> self::assertFalse(...);
        self::assertTrue($attempt->collect(1, 'o'));
        $lateOutput = str_repeat('o', 65_536);
        self::assertFalse($attempt->collect(1, $lateOutput), 'Draining after the stop discards output.');
        $attempt->exit(137);

        self::assertSame(
            ['state' => 'running', 'stdout' => '', 'exitCode' => 137, 'error' => 'Native output byte limit exceeded.'],
            array_intersect_key($attempt->snapshot(), array_flip(['state', 'stdout', 'exitCode', 'error'])),
        );
    }

    public function testAnOverflowFoundAfterTheCommandExitedZeroIsStillAFailureWithoutOutput(): void
    {
        $attempt = $this->newAttempt();
        $attempt->collect(1, str_repeat('o', NativeExecutionProtocol::OUTPUT_BYTES - 10));

        // The final drain after the command exited reads the rest of the pipe.
        $attempt->collect(1, str_repeat('o', 20));
        $attempt->exit(0);

        self::assertSame(1, $attempt->exitCode);
        self::assertSame('', $attempt->snapshot()['stdout']);
        self::assertSame('Native output byte limit exceeded.', $attempt->snapshot()['error']);
    }

    public function testErrorOutputKeepsABoundedTailAndTheCommandsOwnExitCode(): void
    {
        $attempt = $this->newAttempt();
        $attempt->collect(1, '{"streams":[]}');
        $stops = 0;
        for ($line = 0; $line < 9000; $line++) {
            $stops += (int) $attempt->collect(2, str_repeat('e', 999) . "\n");
        }
        $attempt->collect(2, 'last line');
        self::assertSame(0, $stops, 'Error output never stops the command.');
        $attempt->exit(3);

        $snapshot = $attempt->snapshot();
        self::assertSame(3, $snapshot['exitCode']);
        self::assertNull($snapshot['error']);
        self::assertSame('{"streams":[]}', $snapshot['stdout']);
        self::assertStringStartsWith(OutputTail::TRUNCATION_MARKER, $snapshot['stderr']);
        self::assertStringEndsWith('last line', $snapshot['stderr']);
        self::assertLessThanOrEqual(
            NativeExecutionProtocol::ERROR_TAIL_BYTES + strlen(OutputTail::TRUNCATION_MARKER),
            strlen($snapshot['stderr']),
        );
    }

    public function testAnAttemptStoppedForAnotherReasonReturnsNoPartialOutput(): void
    {
        $attempt = $this->newAttempt();
        $attempt->collect(1, '{"streams":[');
        $attempt->error = 'Native execution deadline exceeded.';
        $attempt->exit(137);

        self::assertSame('', $attempt->snapshot()['stdout']);
        self::assertSame(137, $attempt->exitCode);
    }

    private function newAttempt(): NativeExecutionAttempt
    {
        return new NativeExecutionAttempt(str_repeat('a', 64), 'media-video-0:1', 1, str_repeat('b', 64), 4242, $this->process, [], 60.0);
    }
}
