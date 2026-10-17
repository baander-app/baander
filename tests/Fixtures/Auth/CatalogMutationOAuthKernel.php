<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Media\Application\Port\StoragePortInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The production kernel for the catalog and library mutation matrix. The real firewall, the ORM
 * adapters and the disposable PostgreSQL and Redis stay; only the boundaries with external side
 * effects are replaced. The `async` Messenger transport is in-memory so queued scans and
 * cover extraction remain observable without an external consumer. The storage port is a
 * synthetic service the suite sets to a mock, so a cover upload or discard never touches a disk
 * and a refused request proves it was never asked.
 */
final class CatalogMutationOAuthKernel extends ProductionOAuthKernel
{
    public const string DISPATCHER = 'catalog_mutation.event_dispatcher';
    public const string ASYNC_TRANSPORT = 'catalog_mutation.async_transport';
    public const string LIBRARIES = 'catalog_mutation.libraries';

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // The suite's kernel-exception probe listens on the dispatcher.
        $container->setAlias(self::DISPATCHER, 'event_dispatcher')->setPublic(true);
        $container->setAlias(self::LIBRARIES, LibraryRepositoryInterface::class)->setPublic(true);
        // Ahead of the framework's Messenger pass (priority 0), which collects the receivers and
        // senders from the transport definitions as they stand.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $transports = [
                    'async' => CatalogMutationOAuthKernel::ASYNC_TRANSPORT,
                ];
                foreach ($transports as $name => $alias) {
                    $id = 'messenger.transport.' . $name;
                    // The receiver tag keeps the transport's name known to the receiver locator.
                    $tags = $container->getDefinition($id)->getTags();
                    $container->setDefinition($id, (new Definition(InMemoryTransport::class))->setTags($tags)->setPublic(true));
                    $container->setAlias($alias, $id)->setPublic(true);
                }
                // The replaced Redis transport was the only reader of this variable, and the
                // container dumper refuses an environment variable nothing reads.
                $container->setParameter('catalog_mutation.replaced_async_dsn', '%env(MESSENGER_TRANSPORT_DSN)%');
                $container->removeAlias(StoragePortInterface::class);
                $container->setDefinition(StoragePortInterface::class, (new Definition())->setSynthetic(true)->setPublic(true));
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 32);
    }
}
