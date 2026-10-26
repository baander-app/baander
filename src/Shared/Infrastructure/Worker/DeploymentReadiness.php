<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted host observation; grants no lifecycle claims and never submits work. */
#[Exclude]
final readonly class DeploymentReadiness
{
    /** @param Closure(list<string>):string $execute */
    public function __construct(private Connection $connection, private Closure $execute)
    {
    }

    public function verify(DeploymentOperatorManifest $manifest, DeploymentRuntimeEnvironment $environment): void
    {
        $inventory = new DoctrineDeploymentInventory($this->connection);
        $boot = $inventory->findBoot($manifest->namespace, $manifest->bootId);
        if ($boot === null || $boot->manifestHash !== $manifest->fingerprint($environment)
            || $boot->retirementRequested || $boot->retired || $boot->daemonId !== $manifest->daemonId) {
            throw new RuntimeException('Worker readiness requires the exact active deployment manifest.');
        }
        $epoch = $this->activeEpoch($manifest);
        $execute = function (array $arguments) use ($manifest): string {
            if (trim(($this->execute)(['info', '--format', '{{.ID}}'])) !== $manifest->daemonId) {
                throw new RuntimeException('Worker daemon identity changed.');
            }
            return ($this->execute)($arguments);
        };
        foreach ([$boot->control, $boot->execution] as $state) {
            $binding = $state->binding;
            $recipe = $manifest->recipe($state->unit, $environment);
            if ($binding === null || !$state->startClaimed || $state->cancelled || $state->recipeHash !== $recipe->fingerprint()) {
                throw new RuntimeException('Worker readiness requires both admitted started units.');
            }
            (new DockerWorkerContainment($execute, $recipe))->verifyIsolation($binding->containerId, $manifest->namespace, $manifest->bootId, $state->unit);
            $running = json_decode($execute(['container', 'inspect', '--format', '{"id":{{json .Id}},"image":{{json .Image}},"running":{{json .State.Running}},"restarting":{{json .State.Restarting}}}', $binding->containerId]), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($running) || ($running['id'] ?? null) !== $binding->containerId || ($running['image'] ?? null) !== $manifest->imageId
                || ($running['running'] ?? null) !== true || ($running['restarting'] ?? null) !== false) {
                throw new RuntimeException('Admitted worker container is unavailable.');
            }
            $code = 'require "/var/www/html/vendor/autoload.php"; echo json_encode(\\App\\Shared\\Infrastructure\\Worker\\WorkerReadinessObservation::observe($argv[1], $argv[2], (int) $argv[3], $argv[4], $argv[5]), JSON_THROW_ON_ERROR);';
            $roles = json_decode($execute(['exec', $binding->containerId, '/usr/local/bin/php', '-d', 'memory_limit=64M', '-r', $code,
                $manifest->namespace, $manifest->bootId, (string) $epoch, $state->unit->value, $manifest->autoscaling->toJson()]), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($roles) || $roles === []) {
                throw new RuntimeException('Worker readiness observation was not confirmed.');
            }
        }
        if ($this->activeEpoch($manifest) !== $epoch) {
            throw new RuntimeException('Worker lease changed during readiness observation.');
        }
        $current = $inventory->findBoot($manifest->namespace, $manifest->bootId);
        if ($current === null || $current->retirementRequested || $current->retired) {
            throw new RuntimeException('Worker retirement began during readiness observation.');
        }
    }

    private function activeEpoch(DeploymentOperatorManifest $manifest): int
    {
        $epoch = $this->connection->fetchOne("SELECT epoch FROM worker_deployment_leases WHERE namespace = :namespace AND owner_boot_id = :boot AND state = 'active' AND expires_at > clock_timestamp()",
            ['namespace' => $manifest->namespace, 'boot' => $manifest->bootId]);
        if ($epoch === false || (int) $epoch < 1) {
            throw new RuntimeException('Worker lease is not active and unexpired.');
        }
        return (int) $epoch;
    }
}
