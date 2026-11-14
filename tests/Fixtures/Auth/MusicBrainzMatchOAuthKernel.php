<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Production OAuth and the MusicBrainz match routes, with the MusicBrainz web service replaced by what the test sets. */
final class MusicBrainzMatchOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeAlias(MusicBrainzLookupInterface::class);
                $container->setDefinition(MusicBrainzLookupInterface::class, new Definition()->setSynthetic(true)->setPublic(true));
            }
        });
    }
}
