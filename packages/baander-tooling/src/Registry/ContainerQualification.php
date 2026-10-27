<?php

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\SignalScope;
use Baander\Tooling\Runtime\Requirements;
use RuntimeException;
use Throwable;

final class ContainerQualification
{
    /** @var list<string> */
    private array $images = [];
    private string $container = '';
    private string $output = '';
    private string $runId;
    private ProcessRunner $runner;
    private SignalScope $signals;

    public function __construct(private readonly string $root)
    {
        $this->runner = new ProcessRunner();
    }

    public function run(bool $backup): int
    {
        Requirements::check();
        $this->signals = new SignalScope();
        $this->runId = 'baander-registry-' . ($backup ? 'backup-' : 'qualification-') . bin2hex(random_bytes(8));
        $this->output = getenv('REGISTRY_INVENTORY_OUTPUT') ?: $this->root . '/var/test-runs/registry-inventory/' . $this->runId;
        if (!is_dir($this->output) && !mkdir($this->output, 0700, true)) {
            throw new RuntimeException('Cannot create inventory output.');
        }
        $status = 0;
        try {
            $this->command(['docker', 'buildx', 'version'], 'buildx.log', 30);
            $api = $this->image('api', 'Dockerfile');
            $database = $this->image('database', 'rqlite.Dockerfile');
            $images = ['api' => $api, 'rqlite' => $database];
            if ($backup) {
                $images['backup'] = $this->image('backup', 'backup.Dockerfile');
            }
            foreach ($images as $kind => $image) {
                $this->command(
                    [
                        PHP_BINARY,
                        $this->root . '/relay/tools/verify_packaged_image.php',
                        '--image',
                        $image,
                        '--kind',
                        $kind,
                        '--output',
                        $this->output,
                    ],
                    'inventory-' . $kind . '.log',
                    300,
                );
            }
            if (!$backup) {
                $this->command(
                    [
                        PHP_BINARY,
                        $this->root . '/relay/tests/run_container_contract.php',
                        '--api-image',
                        $api,
                        '--rqlite-image',
                        $database,
                    ],
                    'container.log',
                    300,
                );
                echo "Registry production container qualification passed.\n";
            } else {
                $contract = $this->runId . ':contract';
                $this->images[] = $contract;
                $this->command(
                    [
                        'docker',
                        'buildx',
                        'build',
                        '--platform',
                        'linux/amd64',
                        '--load',
                        '-f',
                        $this->root . '/relay/docker/qualification.Dockerfile',
                        '--build-arg',
                        'BACKUP_IMAGE=' . $images['backup'],
                        '--build-arg',
                        'API_IMAGE=' . $api,
                        '--build-arg',
                        'DATABASE_IMAGE=' . $database,
                        '-t',
                        $contract,
                        $this->root . '/relay',
                    ],
                    'build-qualification.log',
                    1800,
                );
                $this->gate(
                    $contract,
                    'encrypted',
                    [
                        'tests/run_backup_qualification.php',
                        '--python',
                        '/usr/bin/python3',
                        '--coordinator',
                        '/usr/local/bin/registry-backup',
                        '--rqlited',
                        '/usr/local/bin/rqlited',
                        '--server',
                        '/usr/local/bin/baander-registry',
                    ],
                    300,
                );
                $this->gate(
                    $contract,
                    'cadence',
                    [
                        'tests/run_backup_cadence_contract.php',
                        '--python',
                        '/usr/bin/python3',
                        '--coordinator',
                        '/usr/local/bin/registry-backup',
                    ],
                    900,
                );
                echo "Registry encrypted backup, recovery, and cadence qualification passed.\n";
            }
        } catch (Throwable $failure) {
            $status = $this->signals->receivedSignal() !== null
                ? 128 + $this->signals->receivedSignal()
                : ($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
            fwrite(STDERR, $failure->getMessage() . "\n");
        } finally {
            if ($this->container !== '' && !$this->cleanup(['docker', 'rm', '-f', $this->container])) {
                $status = $status ?: 1;
            }
            if ($this->images !== [] && !$this->cleanup(['docker', 'image', 'rm', ...$this->images])) {
                $status = $status ?: 1;
            }
            $this->signals->dispatch();
            if ($status === 0 && $this->signals->receivedSignal() !== null) {
                $status = 128 + $this->signals->receivedSignal();
            }
            AtomicFile::writeJson(
                $this->output . '/' . $this->runId . '-receipt.json',
                ['status' => $status === 0 ? 'passed' : 'failed', 'exit_status' => $status, 'backup' => $backup],
            );
            $this->signals->close();
        }
        return $status;
    }

    private function image(string $suffix, string $dockerfile): string
    {
        $image = $this->runId . ':' . $suffix;
        $this->images[] = $image;
        $this->command(
            [
                'docker',
                'buildx',
                'build',
                '--platform',
                'linux/amd64',
                '--load',
                '-f',
                $this->root . '/relay/docker/' . $dockerfile,
                '-t',
                $image,
                $this->root . '/relay',
            ],
            'build-' . $suffix . '.log',
            1800,
        );
        return $image;
    }

    /** @param list<string> $arguments */
    private function gate(string $image, string $gate, array $arguments, float $timeout): void
    {
        AtomicFile::writeJson(
            $this->output . '/' . $this->runId . '-' . $gate . '-gate.json',
            ['gate' => $gate, 'timeout_seconds' => $timeout],
        );
        $this->container = $this->runId . '-' . $gate;
        $this->command(
            [
                'docker',
                'run',
                '--name',
                $this->container,
                '--network',
                'none',
                '--read-only',
                '--tmpfs',
                '/tmp:rw,exec,nosuid,nodev,size=128m,mode=1777',
                '--entrypoint',
                'php',
                $image,
                ...$arguments,
            ],
            $gate . '.log',
            $timeout,
        );
        if (!$this->cleanup(['docker', 'rm', '-f', $this->container])) {
            throw new RuntimeException('Cannot remove completed registry qualification container.');
        }
        $this->container = '';
    }

    /** @param non-empty-list<string> $arguments */
    private function command(array $arguments, string $log, float $timeout): void
    {
        $this->signals->dispatch();
        if ($this->signals->receivedSignal() !== null) {
            throw new RuntimeException('Registry qualification interrupted.', 128 + $this->signals->receivedSignal());
        }
        $path = $this->output . '/' . $this->runId . '-' . $log;
        $result = $this->runner->run(new ProcessSpec(
            $arguments,
            cwd: $this->root,
            environment: getenv(),
            timeout: $timeout,
            logPath: $path,
            processGroup: true,
            terminationGrace: 5,
        ));
        readfile($path);
        if ($result->exitCode !== 0) {
            throw new RuntimeException('Registry gate failed: ' . $log, $result->timedOut ? 124 : $result->exitCode);
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function cleanup(array $arguments): bool
    {
        try {
            return $this->runner->run(new ProcessSpec(
                $arguments,
                environment: getenv(),
                timeout: 30,
                logPath: $this->output . '/' . $this->runId . '-cleanup.log',
                processGroup: true,
                ignoreCancellation: true,
                appendLog: true,
            ))->exitCode === 0;
        } catch (Throwable $failure) {
            fwrite(STDERR, 'Registry cleanup failed: ' . $failure->getMessage() . "\n");
            return false;
        }
    }
}
