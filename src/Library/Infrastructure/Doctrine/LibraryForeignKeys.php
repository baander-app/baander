<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/**
 * Library constraints on columns mapped as scalar IDs: the access owner from
 * Version001_InitialSchema and the file index library from Version20261006190000.
 */
final class LibraryForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_user_library_access_user_id', 'user_library_access', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_library_file_index_library_id', 'library_file_index', 'library_id', 'libraries', 'id', 'CASCADE');
    }
}
