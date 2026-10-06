<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Constraint from Version20261006280000 on the OAuth client owner, mapped as a scalar user ID. */
final class AuthForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_oauth_clients_user_id', 'oauth_clients', 'user_id', 'users', 'id', 'CASCADE');
    }
}
