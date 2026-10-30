<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Runtime;
use PHPUnit\Framework\TestCase;

final class FixtureExecutionTest extends TestCase
{
    public function testExpectedExitPolicyCannotAcceptTimeoutWithGracefulZeroExit(): void
    {
        $runtime = new Runtime(dirname(__DIR__, 3), 5);
        try {
            $runtime->run(['bash', '-c', 'trap "printf cleaned; exit 0" TERM; while true; do sleep 0.02; done'], timeout: 0.15, check: false);
            self::fail('Fixture timeout passed.');
        } catch (\Baander\Tooling\Process\CommandFailure $error) {
            self::assertSame(124, $error->getCode());
            self::assertSame(0, $error->result?->exitCode);
        } finally {
            $runtime->close();
        }
    }

    public function testCleanupCanRunAfterWorkflowDeadlineExpires(): void
    {
        $runtime = new Runtime(dirname(__DIR__, 3), 0.01);
        try {
            usleep(20_000);
            $runtime->cleaning = true;
            self::assertSame('cleanup', $runtime->run(['printf', 'cleanup'])->stdout);
        } finally {
            $runtime->close();
        }
    }
}
