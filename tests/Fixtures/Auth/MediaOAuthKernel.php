<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Media\Application\Port\ImageConversionPortInterface;
use App\Media\Application\Port\StoragePortInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Real OAuth, media adapters and repositories; isolated storage/conversion boundary. */
final class MediaOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('media.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([StoragePortInterface::class, ImageConversionPortInterface::class] as $id) {
                    $container->removeAlias($id);
                    $container->setDefinition($id, (new Definition())->setSynthetic(true)->setPublic(true));
                }
            }
        });
    }
}
