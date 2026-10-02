<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Initial strict containment recipe; not a production deployment configuration. */
#[Exclude]
final readonly class DeploymentContainerRecipe
{
    /** @var list<string> */
    public array $command;

    /** @param array<array-key, mixed> $command Validated argv, never a shell command. */
    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $imageId,
        array $command,
        public string $network,
        public int $memoryBytes,
        public int $nanoCpus,
        public int $pidsLimit,
    ) {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $daemonId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $network) !== 1
            || in_array(strtolower($network), ['host', 'container'], true)
            || $memoryBytes < 16 * 1024 * 1024 || $memoryBytes > 1024 * 1024 * 1024 * 1024
            || $nanoCpus < 1_000_000 || $nanoCpus > 64_000_000_000
            || $pidsLimit < 8 || $pidsLimit > 4096
        ) {
            throw new InvalidArgumentException('Deployment recipe requires bounded identity, immutable image, private network and explicit resource ceilings.');
        }
        if (!array_is_list($command) || count($command) < 1 || count($command) > 64) {
            throw new InvalidArgumentException('Deployment command requires 1..64 ordered arguments.');
        }
        $bytes = 0;
        $validatedCommand = [];
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Deployment arguments must be nonempty bounded strings without NUL bytes.');
            }
            $bytes += strlen($argument);
            $validatedCommand[] = $argument;
        }
        if (!str_starts_with($command[0], '/') || $bytes > 16 * 1024) {
            throw new InvalidArgumentException('Deployment command requires an absolute executable and at most 16 KiB of argument bytes.');
        }
        $this->command = $validatedCommand;
    }

    /** Stable create-intent identity; every admitted configuration field participates. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'namespace' => $this->namespace, 'bootId' => $this->bootId, 'daemonId' => $this->daemonId,
            'imageId' => $this->imageId, 'command' => $this->command, 'network' => $this->network,
            'memoryBytes' => $this->memoryBytes, 'nanoCpus' => $this->nanoCpus, 'pidsLimit' => $this->pidsLimit,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function createArguments(): array
    {
        $wholeCpus = intdiv($this->nanoCpus, 1_000_000_000);
        $fraction = rtrim(sprintf('%09d', $this->nanoCpus % 1_000_000_000), '0');
        $cpus = (string) $wholeCpus . ($fraction === '' ? '' : '.' . $fraction);

        return [
            'container', 'create', '--pull=never', '--name', RegisteredDeploymentStart::containerName($this->namespace, $this->bootId),
            '--network', $this->network, '--memory', (string) $this->memoryBytes, '--memory-swap', (string) $this->memoryBytes,
            '--cpus', $cpus, '--pids-limit', (string) $this->pidsLimit,
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cgroupns', 'private', '--restart', 'no',
            '--label', 'app.baander.worker.namespace=' . $this->namespace,
            '--label', 'app.baander.worker.boot-id=' . $this->bootId, '--label', 'app.baander.worker.role=deployment',
            '--env', 'BAANDER_WORKER_NAMESPACE=' . $this->namespace, '--env', 'BAANDER_WORKER_BOOT_ID=' . $this->bootId,
            '--entrypoint', $this->command[0], $this->imageId, ...array_slice($this->command, 1),
        ];
    }
}
