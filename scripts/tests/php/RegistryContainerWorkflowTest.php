<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryContainerWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-registry-container-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/bin', 0700, true);
        mkdir($this->root . '/relay/tools', 0700, true);
        mkdir($this->root . '/relay/tests', 0700, true);
        symlink(__DIR__ . '/Fixtures/registry-command.php', $this->root . '/bin/docker');
        $script = <<<'PHP'
<?php
file_put_contents(
    getenv('REGISTRY_TEST_STATE') . '/calls',
    basename(__FILE__) . ' ' . implode(' ', array_slice($_SERVER['argv'], 1)) . "\n",
    FILE_APPEND,
);
PHP;
        file_put_contents($this->root . '/relay/tools/verify_packaged_image.php', $script);
        file_put_contents($this->root . '/relay/tests/run_container_contract.php', $script);
    }

    protected function tearDown(): void
    {
        Collector::remove($this->root);
    }

    /** @return array{int,list<string>} */
    private function runWorkflow(string $failure = ''): array
    {
        $environment = array_replace(
            getenv(),
            [
                'PATH' => $this->root . '/bin:' . getenv('PATH'),
                'REGISTRY_TEST_STATE' => $this->root,
                'REGISTRY_INVENTORY_OUTPUT' => $this->root . '/output',
                'STUB_FAIL' => $failure,
            ],
        );
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/registry-container.php', $this->root, 'backup'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertNotSame('', $stdout . $stderr);
        return [$status, file($this->root . '/calls', FILE_IGNORE_NEW_LINES) ?: []];
    }

    public function testEncryptedAndCadenceRemainSeparateBoundedGates(): void
    {
        [$status, $calls] = $this->runWorkflow();
        self::assertSame(0, $status, implode("\n", $calls));
        $runs = array_values(array_filter($calls, static fn(string $line): bool => str_starts_with($line, 'docker run ')));
        self::assertCount(2, $runs);
        self::assertStringContainsString('tests/run_backup_qualification.php', $runs[0]);
        self::assertStringContainsString('tests/run_backup_cadence_contract.php', $runs[1]);
        foreach ($runs as $run) {
            self::assertStringContainsString('--network none --read-only --tmpfs /tmp:rw,exec,nosuid,nodev,size=128m,mode=1777', $run);
            self::assertStringContainsString('--python /usr/bin/python3 --coordinator /usr/local/bin/registry-backup', $run);
        }
        $gates = glob($this->root . '/output/*-gate.json') ?: [];
        $timeouts = [];
        foreach ($gates as $gate) {
            $receipt = json_decode((string) file_get_contents($gate), true, flags: JSON_THROW_ON_ERROR);
            $timeouts[$receipt['gate']] = $receipt['timeout_seconds'];
        }
        self::assertSame(['cadence' => 900, 'encrypted' => 300], $timeouts);
        self::assertStringContainsString('docker image rm baander-registry-backup-', end($calls));
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function failureCases(): iterable
    {
        yield 'encrypted' => ['encrypted', 27, 1];
        yield 'cadence' => ['cadence', 28, 2];
        yield 'cleanup failure' => ['cleanup', 1, 1];
        yield 'primary failure survives cleanup error' => ['encrypted-cleanup', 27, 1];
    }

    #[DataProvider('failureCases')]
    public function testFailureKeepsStatusStopsLaterGatesAndCleansOwnedResources(string $failure, int $expected, int $runs): void
    {
        [$status, $calls] = $this->runWorkflow($failure);
        self::assertSame($expected, $status, implode("\n", $calls));
        self::assertCount($runs, array_filter($calls, static fn(string $line): bool => str_starts_with($line, 'docker run ')));
        self::assertNotEmpty(array_filter(
            $calls,
            static fn(string $line): bool => str_starts_with($line, 'docker rm -f baander-registry-backup-'),
        ));
        self::assertStringContainsString('docker image rm baander-registry-backup-', end($calls));
        $receipts = glob($this->root . '/output/*-receipt.json') ?: [];
        self::assertCount(1, $receipts);
        $receipt = json_decode((string) file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $receipt['exit_status']);
        self::assertSame('failed', $receipt['status']);
    }
}
