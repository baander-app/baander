<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

final class VideoMatchForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_video_match_states_series_id', 'video_match_states', 'series_id', 'tv_series', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_video_match_states_movie_id', 'video_match_states', 'movie_id', 'movies', 'id', 'CASCADE');
    }
}
