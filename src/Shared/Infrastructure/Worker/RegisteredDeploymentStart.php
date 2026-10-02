<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted external startup of one precreated container; never retries an uncertain start. */
#[Exclude]
final readonly class RegisteredDeploymentStart
{
    /** @param Closure(list<string>): string $dockerExecutor Fixed trusted daemon endpoint; bounded I/O; failures throw. */
    public function __construct(private DoctrineDeploymentInventory $inventory, private Closure $dockerExecutor)
    {
    }

    /** Use this unique Docker name when creating the stopped container for a fresh boot. */
    public static function containerName(string $namespace, string $bootId): string
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return 'baander-worker-' . substr(hash('sha256', $namespace), 0, 32) . '-' . $bootId;
    }

    /**
     * A false result means registration/start admission was denied. Exceptions,
     * including an uncertain Docker reply, never authorize repeating a start.
     * The controller must create exactly one stopped container under containerName
     * per boot and reconcile uncertain create outcomes by that name, not create again.
     */
    public function start(DeploymentContainer $binding): bool
    {
        $execute = function (array $arguments) use ($binding): string {
            $daemon = ($this->dockerExecutor)(['info', '--format', '{{.ID}}']);
            if (strlen($daemon) > 256 || trim($daemon) !== $binding->daemonId) {
                throw new \RuntimeException('Docker daemon does not match deployment startup binding.');
            }
            return ($this->dockerExecutor)($arguments);
        };
        $containment = new DockerWorkerContainment($execute);
        $containment->verifyIsolation($binding->containerId, $binding->namespace, $binding->bootId);
        $this->verifyCreated($binding, $execute);
        if (!$this->inventory->register($binding) || !$this->inventory->claimStart($binding)) {
            return false;
        }
        // The one-shot claim is committed before any start call. A failed second
        // inspection burns that claim as well: automatic retries are unsafe.
        $containment->verifyIsolation($binding->containerId, $binding->namespace, $binding->bootId);
        $this->verifyCreated($binding, $execute);
        $started = $execute(['container', 'start', $binding->containerId]);
        if (trim($started) !== $binding->containerId) {
            throw new \RuntimeException('Docker start was not confirmed; deployment requires reconciliation.');
        }
        return true;
    }

    /** @param Closure(list<string>): string $execute */
    private function verifyCreated(DeploymentContainer $binding, Closure $execute): void
    {
        $raw = $execute(['container', 'inspect', '--format', '{"id":{{json .Id}},"name":{{json .Name}},"status":{{json .State.Status}},"running":{{json .State.Running}},"pid":{{json .State.Pid}},"restarting":{{json .State.Restarting}},"restart":{{json .HostConfig.RestartPolicy.Name}},"retryCount":{{json .HostConfig.RestartPolicy.MaximumRetryCount}}}', $binding->containerId]);
        try {
            $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Invalid deployment startup inspection.', 0, $error);
        }
        if (!is_array($state) || count($state) !== 8 || ($state['id'] ?? null) !== $binding->containerId
            || ($state['name'] ?? null) !== '/' . self::containerName($binding->namespace, $binding->bootId)
            || ($state['status'] ?? null) !== 'created' || ($state['running'] ?? null) !== false
            || ($state['pid'] ?? null) !== 0 || ($state['restarting'] ?? null) !== false
            || ($state['restart'] ?? null) !== 'no' || ($state['retryCount'] ?? null) !== 0) {
            throw new \RuntimeException('Startup requires the uniquely named, never-started deployment with restart disabled.');
        }
    }
}
