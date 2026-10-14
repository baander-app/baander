<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Library\Application\Port\LibraryAccessPortInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PlaylistOAuthKernel extends ProductionOAuthKernel
{
    /** The kernel's event dispatcher, for a suite's kernel-exception probe. */
    public const string DISPATCHER = 'playlist.acceptance.event_dispatcher';

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('playlist.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
        $container->setAlias(self::DISPATCHER, 'event_dispatcher')->setPublic(true);
    }
}
