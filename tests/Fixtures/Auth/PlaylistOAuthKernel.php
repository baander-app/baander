<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Application\Port\LibraryAccessPortInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PlaylistOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('playlist.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
    }
}
