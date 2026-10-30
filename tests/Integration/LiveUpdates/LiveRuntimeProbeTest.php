<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LiveRuntimeProbeTest extends TestCase
{
    public function testWorkerTimersBridgeCommittedDatabaseChangesAndStopAtDrain(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $probe = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/LiveUpdates/live-runtime-bridge-probe.php']);
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(40);
        $probe->run();

        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Live runtime bridge probe: idle projection, two workers, console commit, read recovery, reload, pool release and drain passed.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }

    public function testSlowPeerDoesNotHoldHealthyDeliveryOrSendCoroutines(): void
    {
        $probe = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/LiveUpdates/live-runtime-send-probe.php']);
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(30);
        $probe->run();

        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Live runtime send probe: slow peer closed, healthy peer current, coroutines released, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
