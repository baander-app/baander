<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for device and listening-session tables mapped with scalar user IDs. */
final class SessionForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_devices_user_id', 'devices', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_listening_sessions_user_id', 'listening_sessions', 'user_id', 'users', 'id', 'CASCADE');
    }
}
