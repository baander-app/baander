<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Exposes actual catalog application adapters for scope contract assertions. */
final class CatalogOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('catalog.acceptance.library_access', LibraryAccessPortInterface::class)->setPublic(true);
        $container->setAlias('catalog.acceptance.dispatcher', 'event_dispatcher')->setPublic(true);
        $container->setAlias('catalog.acceptance.genres', GenrePortInterface::class)->setPublic(true);
        $container->setAlias('catalog.acceptance.albums', AlbumPortInterface::class)->setPublic(true);
        $container->setAlias('catalog.acceptance.artists', ArtistPortInterface::class)->setPublic(true);
        $container->setAlias('catalog.acceptance.movies', MoviePortInterface::class)->setPublic(true);
        $container->setAlias('catalog.acceptance.songs', SongPortInterface::class)->setPublic(true);
    }
}
