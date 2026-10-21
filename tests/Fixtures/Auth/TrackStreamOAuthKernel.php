<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Media\Application\Port\StreamPortInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Real OAuth, stream service and ORM authorization; disposable filesystem boundary. */
final class TrackStreamOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('track.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
        $container->setAlias('track.acceptance.stream', StreamPortInterface::class)->setPublic(true);
        $container->setAlias('track.acceptance.libraries', LibraryRepositoryInterface::class)->setPublic(true);
        $container->setAlias('track.acceptance.dispatcher', 'event_dispatcher')->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeAlias(LibraryMediaFileResolverInterface::class);
                $container->setDefinition(LibraryMediaFileResolverInterface::class, (new Definition())->setSynthetic(true)->setPublic(true));
            }
        });
    }
}
