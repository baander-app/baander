<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Only joint containment of the fixed pair releases the deployment namespace. */
#[Exclude]
final readonly class RegisteredDeploymentRecovery
{
    /**
     * @param Closure(list<string>): string $dockerExecutor Fixed trusted endpoint and bounded I/O.
     * @param array<string,DeploymentContainerRecipe> $recipes
     */
    public function __construct(
        private DoctrineDeploymentInventory $inventory,
        private DoctrineDeploymentRetirement $retirements,
        private Closure $dockerExecutor,
        private array $recipes,
    ) {
        if (array_keys($recipes) !== ['control', 'execution']) {
            throw new \InvalidArgumentException('Recovery requires exactly the control and execution recipes.');
        }
        foreach (DeploymentUnit::cases() as $unit) {
            if (!isset($recipes[$unit->value]) || $recipes[$unit->value]->unit !== $unit) {
                throw new \InvalidArgumentException('Recovery requires both unit recipes.');
            }
        }
    }

    /** @phpstan-impure */
    public function recover(string $namespace, string $expectedBootId): bool
    {
        $boot = $this->inventory->findBoot($namespace, $expectedBootId);
        if ($boot === null) {
            return false;
        }
        if ($boot->retired) {
            return true;
        }
        foreach ([$boot->control, $boot->execution] as $state) {
            $recipe = $this->recipes[$state->unit->value];
            if ($recipe->namespace !== $namespace || $recipe->bootId !== $expectedBootId
                || $recipe->daemonId !== $boot->daemonId || $recipe->fingerprint() !== $state->recipeHash) {
                throw new \RuntimeException('Recovery recipes differ from the committed deployment.');
            }
        }
        if (!$this->retirements->requestRetirement($namespace, $expectedBootId)) {
            return false;
        }
        $boot = $this->inventory->findBoot($namespace, $expectedBootId);
        if ($boot === null) {
            throw new \RuntimeException('Deployment reservation disappeared during recovery.');
        }
        // Keep control available while execution drains. Each successful proof is
        // committed independently, so a controller interruption can resume safely.
        foreach ([$boot->execution, $boot->control] as $state) {
            if ($state->cancelled || $state->retirement?->completed === true) {
                continue;
            }
            $recipe = $this->recipes[$state->unit->value];
            $binding = $state->binding;
            if ($binding === null) {
                // A claimed but unbound create remains uncertain until a real
                // immutable binding can be verified. A missing name proves nothing.
                $binding = (new DockerDeploymentCreate($this->inventory, $this->dockerExecutor))->reconcile($recipe, forRetirement: true);
            }
            $containment = new DockerWorkerContainment(function (array $arguments) use ($binding): string {
                $daemon = ($this->dockerExecutor)(['info', '--format', '{{.ID}}']);
                if (strlen($daemon) > 256 || trim($daemon) !== $binding->daemonId) {
                    throw new \RuntimeException('Docker daemon differs from the registered deployment.');
                }
                return ($this->dockerExecutor)($arguments);
            }, $recipe);
            if ($state->retirement === null) {
                $containment->verifyIsolation($binding->containerId, $namespace, $expectedBootId, $state->unit);
                if (!$this->retirements->beginUnitRetirement($binding)) {
                    return false;
                }
            }
            $containment->reconcileRemoval($binding->containerId, $namespace, $expectedBootId, $state->unit);
            if (!$this->retirements->completeUnitRetirement($binding)) {
                return false;
            }
        }
        return $this->retirements->completeBootRetirement($namespace, $expectedBootId);
    }
}
