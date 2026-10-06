<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for preference tables mapped with scalar user IDs. */
final class UserPreferenceForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    private const TABLES = [
        'audio_preferences',
        'player_preferences',
        'layout_preferences',
        'user_sidebar_configs',
        'user_accent_colors',
        'eq_device_profiles',
        'preference_history',
    ];

    public function foreignKeys(): iterable
    {
        foreach (self::TABLES as $table) {
            yield new ForeignKeyDeclaration('fk_' . $table . '_user_id', $table, 'user_id', 'users', 'id', 'CASCADE');
        }
    }
}
