<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CommandFailure, EngineExecutor, ExecutionContext};
use Baander\Tooling\Resources\{DisposableContainer, ResourceLedger};
use PHPUnit\Framework\TestCase;

final class DevelopmentDisposableContainerTest extends TestCase
{
    private string $root;
    private Client $docker;
    private EngineExecutor $executor;
    private string $receipt;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-disposable-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $this->root . '/docker');
        chmod($this->root . '/docker', 0700);
        $this->configuration([]);
        $this->executor = new EngineExecutor(ExecutionContext::bounded(10, getenv()));
        $this->docker = new Client($this->executor, new Target($this->root . '/docker', 'unix:///baander.app/fixture.sock', 'owned-daemon', null, null), getenv());
        $this->receipt = $this->root . '/resources.json';
    }

    protected function tearDown(): void
    {
        $this->executor->close();
        Collector::remove($this->root);
    }

    /** @param array<string,mixed> $configuration */
    private function configuration(array $configuration): void
    {
        AtomicFile::writeJson($this->root . '/daemon.json', $configuration);
    }

    public function testAttachedInputAndOutputStayIndependentAndCleanupIsProved(): void
    {
        $this->configuration(['stdout' => '{"complete":true}', 'stderr' => 'independent diagnostic']);
        $result = DisposableContainer::run($this->docker, $this->receipt, ['--network', 'none', 'image'], getenv(), 5, input: '{"input":true}');
        self::assertSame('{"complete":true}', $result->stdout);
        self::assertSame('independent diagnostic', $result->stderr);
        self::assertSame('{"input":true}', file_get_contents($this->root . '/input'));
        $calls = file($this->root . '/calls.jsonl', FILE_IGNORE_NEW_LINES)
            |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
        $created = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'create'))[0];
        $name = $created[array_search('--name', $created, true) + 1];
        self::assertContains(['start', '--attach', '--interactive', hash('sha256', $name)], $calls);
        self::assertContains(['inspect', '--format', '{{.State.ExitCode}}', hash('sha256', $name)], $calls);
        self::assertFileDoesNotExist($this->receipt);
        self::assertSame([], glob($this->root . '/resource-*.json'));
    }

    public function testAttachedDiagnosticsCanUseSeparateEvidenceFiles(): void
    {
        $this->configuration(['stdout' => '{"complete":true}', 'stderr' => 'independent diagnostic']);
        DisposableContainer::run(
            $this->docker,
            $this->receipt,
            ['image'],
            getenv(),
            5,
            logPath: $this->root . '/stdout.log',
            stderrLogPath: $this->root . '/stderr.log',
            terminationGrace: 0.5,
        );
        self::assertSame('{"complete":true}', file_get_contents($this->root . '/stdout.log'));
        self::assertSame('independent diagnostic', file_get_contents($this->root . '/stderr.log'));
        self::assertFileDoesNotExist($this->receipt);
    }

    public function testNonzeroContainerExitKeepsItsStatusThroughFailedCleanupAndTargetedRecovery(): void
    {
        $this->configuration(['exitCode' => 23, 'failRemove' => true]);
        try {
            DisposableContainer::run($this->docker, $this->receipt, ['image'], getenv(), 5);
            self::fail('Expected operation and cleanup failure.');
        } catch (\RuntimeException $error) {
            self::assertSame(23, $error->getCode());
            self::assertStringContainsString('status 23', $error->getMessage());
            self::assertStringContainsString($this->receipt, $error->getMessage());
        }
        self::assertFileExists($this->receipt);
        $this->configuration([]);
        self::assertTrue(ResourceLedger::recover($this->receipt, getenv(), 5)->successful());
        self::assertFileDoesNotExist($this->receipt);
    }

    public function testUncertainCreationIsStillCleanedByRecordedLabelAndIdentity(): void
    {
        $this->configuration(['failCreate' => true]);
        try {
            DisposableContainer::run($this->docker, $this->receipt, ['image'], getenv(), 5);
            self::fail('Expected uncertain creation.');
        } catch (CommandFailure $error) {
            self::assertSame(23, $error->getCode());
        }
        self::assertFileDoesNotExist($this->receipt);
        self::assertSame([], glob($this->root . '/resource-*.json'));
    }
}
