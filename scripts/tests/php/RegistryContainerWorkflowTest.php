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
$target = json_decode(file_get_contents(getenv('BAANDER_TOOLING_TARGET_FILE')), true, flags: JSON_THROW_ON_ERROR);
if (($target['daemonId'] ?? '') !== 'registry-fixture-daemon') {
    exit(92);
}
if (basename(__FILE__) === 'run_container_contract.php' && getenv('STUB_FAIL') === 'nested-leftover') {
    file_put_contents(getenv('BAANDER_REGISTRY_OWNERSHIP_RECEIPT'), json_encode([
        'version' => 1, 'target' => $target, 'token' => str_repeat('a', 32),
        'resources' => [['kind' => 'container', 'name' => 'baander-registry-unresolved', 'id' => null]],
    ], JSON_THROW_ON_ERROR));
}
$child = proc_open(['docker', 'info', '--format', '{{json .}}'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) {
    exit(90);
}
$information = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
$diagnostic = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($child) !== 0 || ($information['ID'] ?? '') !== 'registry-fixture-daemon') {
    fwrite(STDERR, 'Nested helper lost Docker target: ' . $diagnostic);
    exit(91);
}
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

    /** @param array<string,string> $settings
     * @return array{int,list<string>}
     */
    private function runWorkflow(string $failure = '', bool $backup = true, array $settings = []): array
    {
        $environment = array_replace(
            getenv(),
            [
                'PATH' => $this->root . '/bin:' . getenv('PATH'),
                'REGISTRY_TEST_STATE' => $this->root,
                'CI' => '1',
                'REGISTRY_INVENTORY_OUTPUT' => $this->root . '/output',
                'STUB_FAIL' => $failure,
            ],
            $settings,
        );
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/registry-container.php', $this->root, $backup ? 'backup' : 'container'],
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
        file_put_contents($this->root . '/captured-output', $stdout . $stderr);
        return [$status, file($this->root . '/calls', FILE_IGNORE_NEW_LINES) ?: []];
    }

    public function testEncryptedAndCadenceRemainSeparateBoundedGates(): void
    {
        [$status, $calls] = $this->runWorkflow();
        self::assertSame(0, $status, implode("\n", $calls));
        $targets = file($this->root . '/targets', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertNotEmpty($targets);
        self::assertSame(['unix:///var/run/docker.sock'], array_values(array_unique($targets)));
        self::assertSame([], glob($this->root . '/output/*-resources.json') ?: []);
        self::assertSame([], glob($this->root . '/output/*-images.json') ?: []);
        $runs = array_values(array_filter($calls, static fn(string $line): bool => str_starts_with($line, 'docker create ')));
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
        self::assertNotEmpty(array_filter($calls, static fn(string $call): bool => str_starts_with($call, 'docker image rm sha256:')), (string) file_get_contents($this->root . '/captured-output'));
    }

    public function testExecutorCloseFailureKeepsPrimaryStatusAndOutcomeReceipt(): void
    {
        [$status] = $this->runWorkflow('encrypted', settings: ['REGISTRY_TEST_CLOSE_FAILURE' => '1']);
        self::assertSame(27, $status, (string) file_get_contents($this->root . '/captured-output'));
        $receipts = glob($this->root . '/output/*-receipt.json') ?: [];
        self::assertCount(1, $receipts);
        $outcome = json_decode((string) file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(27, $outcome['exit_status']);
        self::assertStringContainsString('injected executor close failure', implode('; ', $outcome['cleanup_errors']));
    }

    public function testParentRejectsSuccessWhileNestedOwnershipReceiptRemains(): void
    {
        self::assertSame(1, $this->runWorkflow('nested-leftover', false)[0]);
        $receipts = glob($this->root . '/output/*-receipt.json') ?: [];
        self::assertCount(1, $receipts);
        $outcome = json_decode((string) file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $outcome['exit_status']);
        self::assertFileExists($outcome['relay_ownership_receipt']);
        self::assertStringContainsString('Nested relay resources remain unresolved', implode('; ', $outcome['cleanup_errors']));
        $ownership = json_decode((string) file_get_contents($outcome['relay_ownership_receipt']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('registry-fixture-daemon', $ownership['target']['daemonId']);
    }

    /** @return iterable<string,array{bool,bool}> */
    public static function imageOwnershipCases(): iterable
    {
        yield 'removed tag keeps immutable ownership' => [true, true];
        yield 'uncertain build without retained tag' => [true, false];
        yield 'foreign retag refuses removal' => [false, true];
    }

    #[DataProvider('imageOwnershipCases')]
    public function testImageOwnershipFollowsImmutableIdsAndRefusesForeignTags(bool $tagRemoved, bool $idRecorded): void
    {
        $image = 'baander-registry-foreign:test';
        $id = 'sha256:' . str_repeat('a', 64);
        $target = new \Baander\Tooling\Docker\Target(
            realpath(__DIR__ . '/Fixtures/registry-command.php') ?: throw new \LogicException('Missing Docker fixture.'),
            'unix:///var/run/docker.sock',
            'registry-fixture-daemon',
            null,
            null,
        );
        $receipt = $this->root . '/images.json';
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($receipt, [
            'version' => 1, 'target' => $target->toArray(), 'token' => str_repeat('a', 32), 'images' => [$image => $idRecorded ? $id : null],
        ]);
        $images = [
            $image => ['Id' => 'sha256:' . str_repeat('b', 64), 'Config' => ['Labels' => ['org.baander.tooling.run' => str_repeat('b', 32)]]],
            '<none>:<none>' => ['Id' => $id, 'Config' => ['Labels' => ['org.baander.tooling.run' => str_repeat('a', 32)]]],
        ];
        if ($tagRemoved) {
            unset($images[$image]);
        }
        file_put_contents($this->root . '/docker-state.json', json_encode(['containers' => [], 'images' => $images], JSON_THROW_ON_ERROR));
        $environment = array_replace(getenv(), ['REGISTRY_TEST_STATE' => $this->root]);
        $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(10, $environment));
        try {
            $docker = new \Baander\Tooling\Docker\Client($executor, $target, $environment);
            $ledger = new \Baander\Tooling\Resources\ImageLedger($receipt, $docker);
            $report = $ledger->cleanup($docker);
            if ($tagRemoved) {
                self::assertTrue($report->successful(), $report->message());
                self::assertFileDoesNotExist($receipt);
                self::assertStringContainsString('image rm ' . $id, (string) file_get_contents($this->root . '/calls'));
                $remaining = json_decode((string) file_get_contents($this->root . '/docker-state.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame([], $remaining['images']);
            } else {
                self::assertFalse($report->successful());
                self::assertStringContainsString('Cannot prove image ownership', $report->message());
                self::assertFileExists($receipt);
                self::assertStringNotContainsString('image rm', (string) file_get_contents($this->root . '/calls'));
            }
        } finally {
            $executor->close();
        }
    }

    public function testFailedImageCleanupCanRetryUsingItsSavedTargetAndIdentities(): void
    {
        self::assertSame(1, $this->runWorkflow('image-cleanup')[0]);
        $receipts = glob($this->root . '/output/*-images.json') ?: [];
        self::assertCount(1, $receipts);
        $saved = json_decode((string) file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        $environment = array_replace(getenv(), ['REGISTRY_TEST_STATE' => $this->root, 'STUB_FAIL' => '']);
        $executor = new \Baander\Tooling\Process\EngineExecutor(
            \Baander\Tooling\Process\ExecutionContext::bounded(30, $environment, ignoreCancellation: true),
        );
        try {
            $docker = new \Baander\Tooling\Docker\Client($executor, \Baander\Tooling\Docker\Target::fromArray($saved['target']), $environment);
            $ledger = new \Baander\Tooling\Resources\ImageLedger($receipts[0], $docker);
            $report = $ledger->cleanup($docker);
            self::assertTrue($report->successful(), $report->message());
            self::assertFileDoesNotExist($receipts[0]);
        } finally {
            $executor->close();
        }
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function failureCases(): iterable
    {
        yield 'encrypted' => ['encrypted', 27, 1];
        yield 'cadence' => ['cadence', 28, 2];
        yield 'cleanup failure' => ['cleanup', 1, 1];
        yield 'image cleanup failure' => ['image-cleanup', 1, 2];
        yield 'primary failure survives cleanup error' => ['encrypted-cleanup', 27, 1];
    }

    #[DataProvider('failureCases')]
    public function testFailureKeepsStatusStopsLaterGatesAndCleansOwnedResources(string $failure, int $expected, int $runs): void
    {
        [$status, $calls] = $this->runWorkflow($failure);
        self::assertSame($expected, $status, implode("\n", $calls));
        self::assertCount($runs, array_filter($calls, static fn(string $line): bool => str_starts_with($line, 'docker create ')));
        self::assertNotEmpty(array_filter(
            $calls,
            static fn(string $line): bool => str_starts_with($line, 'docker rm --force --volumes -- '),
        ));
        self::assertNotEmpty(array_filter($calls, static fn(string $call): bool => str_starts_with($call, 'docker image rm sha256:')), (string) file_get_contents($this->root . '/captured-output'));
        $receipts = glob($this->root . '/output/*-receipt.json') ?: [];
        self::assertCount(1, $receipts);
        $receipt = json_decode((string) file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $receipt['exit_status']);
        self::assertSame('failed', $receipt['status']);
        if (in_array($failure, ['cleanup', 'encrypted-cleanup'], true)) {
            $ownership = glob($this->root . '/output/*-resources.json') ?: [];
            self::assertCount(1, $ownership);
            $state = json_decode((string) file_get_contents($ownership[0]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('registry-fixture-daemon', $state['target']['daemonId']);
            self::assertNotEmpty($state['resources']);
            self::assertNotEmpty($receipt['cleanup_errors']);
        }
        if ($failure === 'image-cleanup') {
            $ownership = glob($this->root . '/output/*-images.json') ?: [];
            self::assertCount(1, $ownership);
            $state = json_decode((string) file_get_contents($ownership[0]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('registry-fixture-daemon', $state['target']['daemonId']);
            self::assertCount(4, $state['images']);
            foreach ($state['images'] as $id) {
                self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $id);
            }
        }
    }
}
