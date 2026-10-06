<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for party tables mapped with scalar user IDs. */
final class PartyForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_party_sessions_host_user_id', 'party_sessions', 'host_user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_party_members_user_id', 'party_members', 'user_id', 'users', 'id', 'CASCADE');
    }
}
