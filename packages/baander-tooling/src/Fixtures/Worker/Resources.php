<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Fixtures\Runtime;
use Throwable;
use Baander\Tooling\Resources\{DisposableContainer, ResourceKind, ResourceLedger};
use Baander\Tooling\Process\ProcessResult;

final class Resources
{
    /** @var list<string> */
    private array $containers = [];
    public readonly string $id;
    public readonly string $artifacts;
    public readonly Client $docker;
    private readonly ResourceLedger $ledger;

    public function __construct(public readonly Runtime $runtime, string $name)
    {
        $this->id = 'baander-' . $name . '-' . time() . '-' . bin2hex(random_bytes(4));
        $this->artifacts = Runtime::artifacts($runtime->root, $name, $runtime->environment);
        $this->docker = $runtime->docker();
        $this->ledger = $runtime->resources($this->artifacts);
    }

    /** @param list<string> $paths */
    public function stage(string $container, array $paths, string $destination = '/tmp'): void
    {
        $archive = $this->artifacts . '/checkout.tar';
        try {
            $this->runtime->run(['tar', '-cf', $archive, ...$paths]);
            $this->docker->run(['cp', $archive, $container . ':/tmp/fixture-checkout.tar']);
            $this->docker->run([
                'exec',
                '--user',
                '0',
                $container,
                'sh',
                '-ec',
                'mkdir -p "$1"; tar -xf /tmp/fixture-checkout.tar -C "$1"; rm /tmp/fixture-checkout.tar',
                'fixture-stage',
                $destination,
            ]);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /** @param list<string> $arguments */
    public function createContainer(string $name, array $arguments): string
    {
        $this->containers[] = $name;
        return $this->ledger->create(ResourceKind::Container, $name, $arguments);
    }

    /** @param list<string> $arguments */
    public function startContainer(string $name, array $arguments): ProcessResult
    {
        $id = $this->createContainer($name, $arguments);
        return $this->docker->run(['start', $id]);
    }

    /** @param list<string> $arguments */
    public function runContainer(string $name, array $arguments): ProcessResult
    {
        return DisposableContainer::run(
            $this->docker,
            $this->artifacts . '/' . $name . '-' . bin2hex(random_bytes(8)) . '.json',
            $arguments,
            $this->runtime->environment,
            $this->runtime->configuration->budgets->seconds('cleanup'),
        );
    }

    /** @param list<string> $arguments */
    public function createNetwork(string $name, array $arguments = []): string
    {
        return $this->ledger->create(ResourceKind::Network, $name, $arguments);
    }

    public function createVolume(string $name): string
    {
        return $this->ledger->create(ResourceKind::Volume, $name, []);
    }

    public function close(): void
    {
        $this->runtime->cleaning = true;
        $docker = new Client($this->runtime->cleanupExecutor(), $this->docker->target, $this->runtime->environment);
        foreach (array_reverse($this->containers) as $container) {
            try {
                $docker->run(
                    ['logs', $container],
                    check: false,
                    timeout: 10,
                    logPath: $this->artifacts . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $container) . '.log',
                );
            } catch (Throwable $error) {
                fwrite(STDERR, 'Log collection failed: ' . $error->getMessage() . "\n");
            }
        }
        try {
            $report = $this->ledger->cleanup($this->runtime->configuration->budgets->seconds('cleanup'));
            Runtime::writeJson($this->artifacts . '/cleanup.json', [
                'cleanupVerified' => $report->successful(), 'errors' => $report->errors,
                'remaining' => $report->successful() ? [] : Runtime::json($this->ledger->receipt)['resources'],
            ]);
            Runtime::require($report->successful(), 'Worker fixture cleanup failed: ' . $report->message());
        } finally {
            $this->runtime->close();
        }
    }
}
