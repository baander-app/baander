<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\DBAL;

use App\Shared\Infrastructure\Swoole\DBAL\OptimizedDBALAliveKeeper;
use App\Shared\Infrastructure\Swoole\DBAL\PassiveIgnoringDBALAliveKeeper;
use App\Shared\Infrastructure\Swoole\DBAL\PingingDBALAliveKeeper;
use App\Shared\Infrastructure\Swoole\DBAL\TransactionFinalizingDBALAliveKeeper;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALPlatformAliveKeeper;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wires DBAL alive-keepers into the coroutine service pool via DBALPlatformAliveKeeper.
 * Replaces the resetter-bundle's AliveKeeperPass — no kernel.request listener is registered.
 * The DoctrineProcessor (StatefulServicesPass) consumes the alive-keepers from
 * DBALPlatformAliveKeeper and creates ConnectionKeepAliveInitializer instances per connection.
 */
final class DBALAliveKeeperCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');

        if (empty($connections)) {
            return;
        }

        $pingInterval = $this->getPingInterval($container);
        $checkActiveTransactions = $this->getCheckActiveTransactions($container);
        $aliveKeepers = [];
        $connectionRefs = [];

        foreach ($connections as $connectionName => $connectionSvcId) {
            $connectionRefs[$connectionName] = new Reference($connectionSvcId);

            $aliveKeeperSvcId = sprintf('app.swoole.alive_keeper.dbal.%s', $connectionName);
            $container->setDefinition(
                $aliveKeeperSvcId,
                $container->findDefinition(PingingDBALAliveKeeper::class),
            );

            // Wrap with TransactionFinalizing (priority 1)
            if ($checkActiveTransactions) {
                $decoratorSvcId = sprintf(
                    '%s_%s',
                    TransactionFinalizingDBALAliveKeeper::class,
                    $connectionName,
                );
                $innerSvcId = sprintf('%s.inner', $decoratorSvcId);
                $def = new ChildDefinition(TransactionFinalizingDBALAliveKeeper::class);
                $def->setDecoratedService($aliveKeeperSvcId, $innerSvcId, 0);
                $def->setArgument('$decorated', new Reference($innerSvcId));
                $container->setDefinition($decoratorSvcId, $def);
            }

            // Wrap with PassiveIgnoring (default priority 0)
            $passiveSvcId = sprintf(
                '%s_%s',
                PassiveIgnoringDBALAliveKeeper::class,
                $connectionName,
            );
            $passiveInnerSvcId = sprintf('%s.inner', $passiveSvcId);
            $passiveDef = new ChildDefinition(PassiveIgnoringDBALAliveKeeper::class);
            $passiveDef->setDecoratedService($aliveKeeperSvcId, $passiveInnerSvcId, 1);
            $passiveDef->setArgument('$decorated', new Reference($passiveInnerSvcId));
            $container->setDefinition($passiveSvcId, $passiveDef);

            // Wrap with Optimized (priority 2) — outermost, throttles all calls
            if ($pingInterval > 0) {
                $optSvcId = sprintf('%s_%s', OptimizedDBALAliveKeeper::class, $connectionName);
                $optInnerSvcId = sprintf('%s.inner', $optSvcId);
                $optDef = new ChildDefinition(OptimizedDBALAliveKeeper::class);
                $optDef->setDecoratedService($aliveKeeperSvcId, $optInnerSvcId, 2);
                $optDef->setArgument('$decorated', new Reference($optInnerSvcId));
                $optDef->setArgument('$pingIntervalInSeconds', $pingInterval);
                $container->setDefinition($optSvcId, $optDef);
            }

            $aliveKeepers[$connectionName] = new Reference($aliveKeeperSvcId);
        }

        // Wire DBALPlatformAliveKeeper so DoctrineProcessor can consume it
        $aliveKeeperDef = $container->findDefinition(DBALPlatformAliveKeeper::class);
        $aliveKeeperDef->setArgument('$connections', $connectionRefs);
        $aliveKeeperDef->setArgument('$aliveKeepers', $aliveKeepers);
    }

    private function getPingInterval(ContainerBuilder $container): int
    {
        return (int) ($container->hasParameter('app.swoole.dbal.ping_interval')
            ? $container->getParameter('app.swoole.dbal.ping_interval')
            : 0);
    }

    private function getCheckActiveTransactions(ContainerBuilder $container): bool
    {
        return (bool) ($container->hasParameter('app.swoole.dbal.check_active_transactions')
            ? $container->getParameter('app.swoole.dbal.check_active_transactions')
            : false);
    }
}
