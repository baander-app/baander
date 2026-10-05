<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/** Keeps cross-context referential integrity independent of ORM associations. */
final class PreferenceOwnershipSchemaListener
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

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        foreach (self::TABLES as $name) {
            if (!$schema->hasTable($name)) {
                continue;
            }

            // The users table can be external to a focused SchemaTool fixture.
            // Keep these migration-defined constraints visible to schema comparison.
            $schema->getTable($name)->addForeignKeyConstraint(
                'users',
                ['user_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_' . $name . '_user_id',
            );
        }
    }
}
