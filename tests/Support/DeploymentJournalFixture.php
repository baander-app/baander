<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use Doctrine\DBAL\Connection;

/** Real journal transitions for lease tests; no simulated singleton ownership. */
final class DeploymentJournalFixture
{
    /** @return array{DeploymentContainer, DeploymentContainer} */
    public static function ready(Connection $connection, string $namespace, string $bootId): array
    {
        $inventory = new DoctrineDeploymentInventory($connection);
        if (!$inventory->reserveBoot($namespace, $bootId, str_repeat('f', 64), 'baander.app:daemon', str_repeat('1', 64), str_repeat('2', 64))) {
            throw new \RuntimeException('Cannot reserve fixture deployment.');
        }
        $bindings = [];
        foreach (DeploymentUnit::cases() as $unit) {
            $binding = new DeploymentContainer($namespace, $bootId, 'baander.app:daemon', hash('sha256', $namespace . $bootId . $unit->value), $unit);
            if (!$inventory->claimCreate($namespace, $bootId, $unit) || !$inventory->register($binding)) {
                throw new \RuntimeException('Cannot bind fixture deployment.');
            }
            $bindings[] = $binding;
        }
        if (!$inventory->claimStart($bindings[0])) {
            throw new \RuntimeException('Cannot claim fixture control start.');
        }
        return [$bindings[0], $bindings[1]];
    }

    public static function retire(Connection $connection, string $namespace, string $bootId): bool
    {
        $inventory = new DoctrineDeploymentInventory($connection);
        $retirement = new DoctrineDeploymentRetirement($connection);
        if (!$retirement->requestRetirement($namespace, $bootId)) {
            return false;
        }
        foreach (DeploymentUnit::cases() as $unit) {
            $binding = $inventory->findUnit($namespace, $bootId, $unit);
            if ($binding !== null && (!$retirement->beginUnitRetirement($binding) || !$retirement->completeUnitRetirement($binding))) {
                return false;
            }
        }
        return $retirement->completeBootRetirement($namespace, $bootId);
    }
}
