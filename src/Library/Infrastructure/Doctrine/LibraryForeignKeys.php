<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraint from Version001_InitialSchema for library access mapped with a scalar user ID. */
final class LibraryForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_user_library_access_user', 'user_library_access', 'user_id', 'users', 'id', 'CASCADE');
    }
}
