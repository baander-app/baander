<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Lyrics\Application\Port\LrclibClientInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Production OAuth and lyrics service with an isolated remote-provider boundary. */
final class LyricsOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('lyrics.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeAlias(LrclibClientInterface::class);
                $container->setDefinition(LrclibClientInterface::class, (new Definition())->setSynthetic(true)->setPublic(true));
            }
        });
    }
}
