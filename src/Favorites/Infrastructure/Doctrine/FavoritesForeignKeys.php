<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraint from Version20261006280000 for favorites mapped with scalar user IDs. */
final class FavoritesForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_user_favorites_user_id', 'user_favorites', 'user_id', 'users', 'id', 'CASCADE');
    }
}
