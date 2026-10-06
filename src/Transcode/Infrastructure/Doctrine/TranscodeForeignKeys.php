<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for transcode sessions mapped with scalar user IDs. */
final class TranscodeForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_transcode_sessions_user_id', 'transcode_sessions', 'user_id', 'users', 'id', 'CASCADE');
    }
}
