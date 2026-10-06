<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Song constraint from Version001_InitialSchema for lyrics mapped with a scalar song ID. */
final class LyricsForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_lyrics_song_id', 'lyrics', 'song_id', 'songs', 'id', 'CASCADE');
    }
}
