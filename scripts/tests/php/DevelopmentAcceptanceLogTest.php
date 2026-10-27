<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Acceptance\Commands;
use Baander\Tooling\Development\Acceptance\Support;
use PHPUnit\Framework\TestCase;

final class DevelopmentAcceptanceLogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Support::temporary('baander-acceptance-log-test-');
        mkdir($this->root . '/logs', 0700);
    }

    protected function tearDown(): void
    {
        Support::removeTree($this->root);
    }

    /** @return non-empty-list<string> */
    private function child(string $mode, string ...$arguments): array
    {
        return [PHP_BINARY, __DIR__ . '/Fixtures/acceptance-command.php', $mode, ...$arguments];
    }

    public function testCommandReturnsMergedOutputAndRetainsPrivateLog(): void
    {
        $commands = new Commands($this->root, $this->root . '/logs');
        $output = $commands->run($this->child('output'));
        self::assertStringContainsString('qualified-revision', $output);
        self::assertStringContainsString('command-diagnostic', $output);
        $logs = glob($this->root . '/logs/*') ?: [];
        self::assertCount(1, $logs);
        self::assertSame($output, file_get_contents($logs[0]));
        self::assertSame(0600, fileperms($logs[0]) & 0777);
    }

    public function testAsyncOutputKeepsDrainingDuringConcurrentPollingCommand(): void
    {
        $commands = new Commands($this->root, $this->root . '/logs');
        $marker = $this->root . '/producer-finished';
        $producer = $commands->start($this->child('producer', $marker), timeout: 6);
        try {
            self::assertSame('producer-observed', $commands->run($this->child('poll', $marker)));
            $result = $commands->wait($producer);
            self::assertSame(0, $result->exitCode);
            self::assertFalse($result->timedOut);
            self::assertFalse($result->cancelled);
            self::assertSame('', $result->stdout);
            self::assertSame('', $result->stderr);
            $logs = glob($this->root . '/logs/*') ?: [];
            self::assertCount(2, $logs);
            $contents = array_map(file_get_contents(...), $logs);
            usort($contents, static fn(string $first, string $second): int => strlen($second) <=> strlen($first));
            self::assertSame(4_194_304, strlen($contents[0]));
            self::assertSame(2_097_152, substr_count($contents[0], 'o'));
            self::assertSame(2_097_152, substr_count($contents[0], 'e'));
            foreach ($logs as $log) {
                self::assertSame(0600, fileperms($log) & 0777);
            }
        } finally {
            $producer->dispose();
        }
    }

    public function testPollingPauseIsBoundedAndDrainsAsyncOutput(): void
    {
        $result = Support::run($this->child('pause-driver', $this->root), timeout: 4, check: false);
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertFalse($result->timedOut);
        $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertLessThan(1, $report['idleSeconds']);
        self::assertSame(0, $report['status']);
        self::assertFileExists($this->root . '/finished');
        $logs = glob($this->root . '/logs/*') ?: [];
        self::assertCount(1, $logs);
        self::assertSame(4194304, filesize($logs[0]));
    }

    public function testParentSignalStopsOwnedSilentChildAndRetainsLog(): void
    {
        $result = Support::run($this->child('signal-driver', $this->root), timeout: 6);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['cancelled']);
        $pid = (int) file_get_contents($this->root . '/child-pid');
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        self::assertTrue($stat === false || preg_match('/\) Z /', $stat) === 1, 'The owned child must have exited.');
        $logs = glob($this->root . '/logs/*') ?: [];
        self::assertCount(1, $logs);
        self::assertSame('silent-child-ready', file_get_contents($logs[0]));
        self::assertSame(0600, fileperms($logs[0]) & 0777);
    }
}
