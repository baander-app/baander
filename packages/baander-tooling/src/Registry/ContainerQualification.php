<?php

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Resources\ResourceLedger;
use Baander\Tooling\Resources\ResourceKind;
use Baander\Tooling\Resources\ImageLedger;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\SignalScope;
use Baander\Tooling\Runtime\Requirements;
use RuntimeException;
use Throwable;

final class ContainerQualification
{
    private string $output = '';
    private string $relayReceipt = '';
    private string $runId;
    private EngineExecutor $runner;
    private Client $docker;
    private ResourceLedger $ledger;
    private ToolingConfiguration $configuration;
    /** @var array<string,string> */
    private array $environment;
    private ImageLedger $imageLedger;
    private SignalScope $signals;

    public function __construct(private readonly string $root)
    {
        $this->environment = getenv();
        $this->configuration = ToolingConfiguration::forRepository($root, $this->environment);
        $this->runner = new EngineExecutor(ExecutionContext::bounded($this->configuration->budgets->seconds('registryQualification'), $this->environment, $root));
    }

    public function run(bool $backup): int
    {
        Requirements::check();
        $this->signals = new SignalScope();
        $this->runId = 'baander-registry-' . ($backup ? 'backup-' : 'qualification-') . bin2hex(random_bytes(8));
        $this->output = ($this->environment['REGISTRY_INVENTORY_OUTPUT'] ?? '') ?: $this->root . '/var/test-runs/registry-inventory/' . $this->runId;
        if (!is_dir($this->output) && !mkdir($this->output, 0700, true)) {
            throw new RuntimeException('Cannot create inventory output.');
        }
        $this->output = realpath($this->output) ?: throw new RuntimeException('Cannot resolve registry output directory.');
        $status = 0;
        try {
            $target = new TargetResolver($this->runner, $this->environment)->resolve($this->configuration->docker, $this->output . '/' . $this->runId . '-transport');
            $this->docker = new Client($this->runner, $target, $this->environment);
            $this->imageLedger = new ImageLedger($this->output . '/' . $this->runId . '-images.json', $this->docker);
            $this->ledger = new ResourceLedger($this->output . '/' . $this->runId . '-resources.json', $this->docker, $this->environment);
            $bin = $this->output . '/' . $this->runId . '-bin';
            if (!mkdir($bin, 0700)) {
                throw new RuntimeException('Cannot create nested Docker transport adapter.');
            }
            $argv = [$target->executable, '--host', $target->endpoint, ...$target->transportArguments];
            AtomicFile::write($bin . '/docker', "#!/bin/sh\nexec " . implode(' ', array_map(escapeshellarg(...), $argv)) . ' "$@"' . "\n", 0700);
            $this->environment = Client::transportEnvironment($this->environment);
            $this->environment['PATH'] = $bin . ':' . ($this->environment['PATH'] ?? '');
            $targetFile = $this->output . '/' . $this->runId . '-target.json';
            AtomicFile::writeJson($targetFile, $target->toArray());
            $this->relayReceipt = $this->output . '/' . $this->runId . '-relay-resources.json';
            $this->environment['BAANDER_TOOLING_TARGET_FILE'] = $targetFile;
            $this->environment['BAANDER_REGISTRY_OWNERSHIP_RECEIPT'] = $this->relayReceipt;
            $this->environment['BAANDER_CLEANUP_TIMEOUT'] = (string) $this->configuration->budgets->seconds('cleanup');
            $this->dockerCommand(['buildx', 'version'], 'buildx.log', 30);
            $api = $this->image('api', 'Dockerfile');
            $database = $this->image('database', 'rqlite.Dockerfile');
            $images = ['api' => $api, 'rqlite' => $database];
            if ($backup) {
                $images['backup'] = $this->image('backup', 'backup.Dockerfile');
            }
            foreach ($images as $kind => $image) {
                $this->command(new ProcessSpec(
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
                    cwd: $this->root,
                    environment: $this->environment,
                    timeout: 300,
                    logPath: $this->logPath('inventory-' . $kind . '.log'),
                    processGroup: true,
                    terminationGrace: 5,
                    replaceEnvironment: true,
                ));
            }
            if (!$backup) {
                $this->command(new ProcessSpec(
                    [
                        PHP_BINARY,
                        $this->root . '/relay/tests/run_container_contract.php',
                        '--api-image',
                        $api,
                        '--rqlite-image',
                        $database,
                    ],
                    cwd: $this->root,
                    environment: $this->environment,
                    timeout: 300,
                    logPath: $this->logPath('container.log'),
                    processGroup: true,
                    terminationGrace: 5,
                    replaceEnvironment: true,
                ));
                echo "Registry production container qualification passed.\n";
            } else {
                $contract = $this->runId . ':contract';
                $this->imageLedger->intend($contract);
                $this->dockerCommand(
                    [
                        'buildx',
                        'build',
                        '--platform',
                        'linux/amd64',
                        '--load',
                        '--label',
                        'org.baander.tooling.run=' . $this->imageLedger->token,
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
                $this->imageLedger->observe($contract);
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
            $cleanupErrors = [];
            if ($this->relayReceipt !== '' && (file_exists($this->relayReceipt) || is_link($this->relayReceipt))) {
                $cleanupErrors[] = 'Nested relay resources remain unresolved. Retry receipt: ' . $this->relayReceipt;
            }
            $cleanupContext = null;
            try {
                $cleanupContext = ExecutionContext::bounded($this->configuration->budgets->seconds('cleanup'), $this->environment, ignoreCancellation: true);
                if (isset($this->ledger)) {
                    $report = $this->ledger->cleanup($cleanupContext->remaining());
                    if (!$report->successful()) {
                        $cleanupErrors[] = $report->message();
                    }
                }
            } catch (Throwable $failure) {
                $cleanupErrors[] = $failure->getMessage();
            }
            $cleanupExecutor = null;
            try {
                if (isset($this->imageLedger) && $cleanupContext !== null) {
                    $cleanupExecutor = new EngineExecutor($cleanupContext);
                    $report = $this->imageLedger->cleanup(new Client($cleanupExecutor, $this->docker->target, $this->environment));
                    if (!$report->successful()) {
                        $cleanupErrors[] = $report->message();
                    }
                }
            } catch (Throwable $failure) {
                $cleanupErrors[] = $failure->getMessage();
            } finally {
                try {
                    $cleanupExecutor?->close();
                } catch (Throwable $failure) {
                    $cleanupErrors[] = 'Cannot close registry image cleanup: ' . $failure->getMessage();
                }
            }
            try {
                $this->signals->dispatch();
            } catch (Throwable $failure) {
                $cleanupErrors[] = $failure->getMessage();
            }
            if ($status === 0 && $this->signals->receivedSignal() !== null) {
                $status = 128 + $this->signals->receivedSignal();
            }
            foreach ([$this->runner, $this->signals] as $resource) {
                try {
                    $resource->close();
                } catch (Throwable $failure) {
                    $cleanupErrors[] = 'Cannot close registry execution: ' . $failure->getMessage();
                }
            }
            foreach ($cleanupErrors as $error) {
                fwrite(STDERR, 'Registry cleanup failed: ' . $error . "\n");
                $status = $status ?: 1;
            }
            try {
                AtomicFile::writeJson(
                    $this->output . '/' . $this->runId . '-receipt.json',
                    ['status' => $status === 0 ? 'passed' : 'failed', 'exit_status' => $status, 'backup' => $backup, 'cleanup_errors' => $cleanupErrors, 'relay_ownership_receipt' => $this->relayReceipt],
                );
            } catch (Throwable $failure) {
                $status = $status ?: 1;
                fwrite(STDERR, 'Cannot write registry outcome: ' . $failure->getMessage() . "\n");
            }
        }
        return $status;
    }

    private function image(string $suffix, string $dockerfile): string
    {
        $image = $this->runId . ':' . $suffix;
        $this->imageLedger->intend($image);
        $this->dockerCommand(
            [
                'buildx',
                'build',
                '--platform',
                'linux/amd64',
                '--load',
                '--label',
                'org.baander.tooling.run=' . $this->imageLedger->token,
                '-f',
                $this->root . '/relay/docker/' . $dockerfile,
                '-t',
                $image,
                $this->root . '/relay',
            ],
            'build-' . $suffix . '.log',
            1800,
        );
        $this->imageLedger->observe($image);
        return $image;
    }

    /** @param list<string> $arguments */
    private function gate(string $image, string $gate, array $arguments, float $timeout): void
    {
        AtomicFile::writeJson(
            $this->output . '/' . $this->runId . '-' . $gate . '-gate.json',
            ['gate' => $gate, 'timeout_seconds' => $timeout],
        );
        $container = $this->runId . '-' . $gate;
        $id = $this->ledger->create(ResourceKind::Container, $container, [
            '--network',
            'none',
            '--read-only',
            '--tmpfs',
            '/tmp:rw,exec,nosuid,nodev,size=128m,mode=1777',
            '--entrypoint',
            'php',
            $image,
            ...$arguments,
        ]);
        $this->dockerCommand(['start', '--attach', $id], $gate . '.log', $timeout);
        $report = $this->ledger->cleanup($this->configuration->budgets->seconds('cleanup'));
        if (!$report->successful()) {
            throw new RuntimeException($report->message());
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function dockerCommand(array $arguments, string $log, float $timeout): void
    {
        $this->command($this->docker->command(
            $arguments,
            cwd: $this->root,
            timeout: $timeout,
            logPath: $this->logPath($log),
        ));
    }

    private function logPath(string $name): string
    {
        return $this->output . '/' . $this->runId . '-' . $name;
    }

    private function command(ProcessSpec $command): void
    {
        try {
            $this->runner->run($command);
        } finally {
            if ($command->logPath !== null && is_file($command->logPath)) {
                readfile($command->logPath);
            }
        }
    }
}
