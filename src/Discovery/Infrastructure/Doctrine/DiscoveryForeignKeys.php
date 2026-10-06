<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Constraint from Version20261006280000 on the pairing session server, mapped as a scalar ID. */
final class DiscoveryForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_pairing_sessions_server_id', 'pairing_sessions', 'server_id', 'server_instances', 'id', 'CASCADE');
    }
}
