<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

final class ActivityForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_watched_marks_user_id', 'watched_marks', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_watched_marks_episode_id', 'watched_marks', 'episode_id', 'tv_episodes', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_watched_marks_movie_id', 'watched_marks', 'movie_id', 'movies', 'id', 'CASCADE');
    }
}
