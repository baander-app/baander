<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Song constraint from Version001_InitialSchema for playlist entries mapped with a scalar song ID. */
final class PlaylistForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_playlist_song_song_id', 'playlist_song', 'song_id', 'songs', 'id', 'CASCADE');
    }
}
