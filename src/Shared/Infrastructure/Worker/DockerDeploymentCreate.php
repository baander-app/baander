<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted external creation: uncertain outcomes require explicit name reconciliation, never blind recreation. */
#[Exclude]
final readonly class DockerDeploymentCreate
{
    private const string FORMAT = <<<'FORMAT'
{"id":{{json .Id}},"name":{{json .Name}},"image":{{json .Image}},"entrypoint":{{json .Config.Entrypoint}},"cmd":{{json .Config.Cmd}},"env":{{json .Config.Env}},"network":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}},"memory":{{json .HostConfig.Memory}},"memorySwap":{{json .HostConfig.MemorySwap}},"nanoCpus":{{json .HostConfig.NanoCpus}},"pidsLimit":{{json .HostConfig.PidsLimit}},"status":{{json .State.Status}},"running":{{json .State.Running}},"pid":{{json .State.Pid}},"restarting":{{json .State.Restarting}},"restart":{{json .HostConfig.RestartPolicy.Name}},"retryCount":{{json .HostConfig.RestartPolicy.MaximumRetryCount}}}
FORMAT;

    /** @param Closure(list<string>): string $dockerExecutor Fixed trusted endpoint; bounded output and deadlines; failures throw. */
    public function __construct(private DoctrineDeploymentInventory $inventory, private Closure $dockerExecutor)
    {
    }

    /** One create attempt; any error leaves the caller responsible for reconciliation. Does not start processes. */
    public function create(DeploymentContainerRecipe $recipe): DeploymentContainer
    {
        if ($this->inventory->find($recipe->namespace, $recipe->bootId) !== null) {
            throw new \RuntimeException('Deployment boot is already registered; creation is forbidden.');
        }
        if (!$this->inventory->claimCreate($recipe)) {
            throw new \RuntimeException('Deployment creation was already attempted; reconcile without recreating.');
        }
        $id = trim($recipe->runtimeEnvironment->variables === []
            ? $this->execute($recipe, $recipe->createArguments())
            : $recipe->runtimeEnvironment->withFile(fn (string $path): string => $this->execute($recipe, $recipe->createArguments($path))));
        if (preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) {
            throw new \RuntimeException('Docker creation was not confirmed; reconcile the deterministic name.');
        }
        return $this->inspectAndRegister($recipe, $id);
    }

    /** Recover a lost create/registration reply by name, without creating or starting anything. */
    public function reconcile(DeploymentContainerRecipe $recipe): DeploymentContainer
    {
        if (!$this->inventory->matchesCreate($recipe)) {
            throw new \RuntimeException('Deployment recipe does not match a committed creation intent.');
        }
        return $this->inspectAndRegister($recipe, RegisteredDeploymentStart::containerName($recipe->namespace, $recipe->bootId));
    }

    private function inspectAndRegister(DeploymentContainerRecipe $recipe, string $target): DeploymentContainer
    {
        $raw = $this->execute($recipe, ['container', 'inspect', '--format', self::FORMAT, $target]);
        try {
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Invalid deployment creation inspection.', 0, $error);
        }
        if (!is_array($state) || count($state) !== 18 || !is_string($state['id'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $state['id']) !== 1
            || ($target !== RegisteredDeploymentStart::containerName($recipe->namespace, $recipe->bootId) && $state['id'] !== $target)
            || ($state['name'] ?? null) !== '/' . RegisteredDeploymentStart::containerName($recipe->namespace, $recipe->bootId)
            || ($state['image'] ?? null) !== $recipe->imageId
            || ($state['entrypoint'] ?? null) !== [$recipe->command[0]]
            || !array_key_exists('cmd', $state) || ($state['cmd'] ?? []) !== array_slice($recipe->command, 1)
            || ($state['network'] ?? null) !== $recipe->network
            || !is_array($state['networks'] ?? null) || array_keys($state['networks']) !== [$recipe->network]
            || ($state['memory'] ?? null) !== $recipe->memoryBytes
            || ($state['memorySwap'] ?? null) !== $recipe->memoryBytes
            || ($state['nanoCpus'] ?? null) !== $recipe->nanoCpus
            || ($state['pidsLimit'] ?? null) !== $recipe->pidsLimit
            || ($state['status'] ?? null) !== 'created' || ($state['running'] ?? null) !== false
            || ($state['pid'] ?? null) !== 0 || ($state['restarting'] ?? null) !== false
            || ($state['restart'] ?? null) !== 'no' || ($state['retryCount'] ?? null) !== 0
            || !is_array($state['env'] ?? null)) {
            throw new \RuntimeException('Created container does not match the deployment recipe.');
        }
        $expected = ['BAANDER_WORKER_NAMESPACE' => $recipe->namespace, 'BAANDER_WORKER_BOOT_ID' => $recipe->bootId,
            ...$recipe->runtimeEnvironment->variables];
        foreach ($state['env'] as $item) {
            if (!is_string($item) || !str_contains($item, '=')) {
                throw new \RuntimeException('Created container has invalid environment metadata.');
            }
            $key = explode('=', $item, 2)[0];
            if (str_starts_with($key, 'BAANDER_WORKER_') && !array_key_exists($key, $expected)) {
                throw new \RuntimeException('Created container has an unexpected worker control environment.');
            }
        }
        foreach ($expected as $key => $value) {
            $matches = array_values(array_filter($state['env'], static fn (string $item): bool => str_starts_with($item, $key . '=')));
            if ($matches !== [$key . '=' . $value]) {
                throw new \RuntimeException('Created container environment does not match its committed recipe.');
            }
        }
        $binding = new DeploymentContainer($recipe->namespace, $recipe->bootId, $recipe->daemonId, $state['id']);
        (new DockerWorkerContainment(fn (array $arguments): string => $this->execute($recipe, $arguments)))
            ->verifyIsolation($binding->containerId, $binding->namespace, $binding->bootId);
        if (!$this->inventory->register($binding)) {
            throw new \RuntimeException('Created container conflicts with immutable deployment inventory.');
        }
        return $binding;
    }

    /** @param list<string> $arguments */
    private function execute(DeploymentContainerRecipe $recipe, array $arguments): string
    {
        $daemon = ($this->dockerExecutor)(['info', '--format', '{{.ID}}']);
        if (strlen($daemon) > 256 || trim($daemon) !== $recipe->daemonId) {
            throw new \RuntimeException('Docker daemon does not match deployment creation recipe.');
        }
        return ($this->dockerExecutor)($arguments);
    }
}
