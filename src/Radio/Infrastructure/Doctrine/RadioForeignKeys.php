<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for radio tables mapped with scalar user IDs. */
final class RadioForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_country_subscriptions_user_id', 'country_subscriptions', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_radio_sessions_user_id', 'radio_sessions', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_starred_stations_user_id', 'starred_stations', 'user_id', 'users', 'id', 'CASCADE');
    }
}
