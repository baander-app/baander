<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

final class VideoArtworkForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_images_library_id', 'images', 'library_id', 'libraries', 'id', 'CASCADE');
    }
}
