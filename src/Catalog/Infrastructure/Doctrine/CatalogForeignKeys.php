<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

final class CatalogForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_library_provider_orders_library_id', 'library_provider_orders', 'library_id', 'libraries', 'id', 'CASCADE');
    }
}
