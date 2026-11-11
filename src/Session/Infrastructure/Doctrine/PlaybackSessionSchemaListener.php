<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Declares the owner-scoped composite foreign keys between the session and playback tables.
 *
 * Each key references (id, user_id), a unique key that is not the target's identifier, so
 * an ORM association cannot express it and the entities hold the referencing columns as
 * scalars. Without these declarations schema comparison would plan to drop every key.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class PlaybackSessionSchemaListener
{
    /** @var list<array{string, string, string, string, 'CASCADE'|null}> owning table, constraint, referencing column, referenced table, delete rule */
    private const array FOREIGN_KEYS = [
        ['listening_sessions', 'fk_listening_sessions_selected_destination_id_user_id', 'selected_destination_id', 'playback_destinations', null],
        ['listening_sessions', 'fk_listening_sessions_active_incarnation_id_user_id', 'active_incarnation_id', 'playback_destination_incarnations', null],
        ['playback_destination_incarnations', 'fk_playback_destination_incarnations_destination_id_user_id', 'destination_id', 'playback_destinations', 'CASCADE'],
        ['playback_transfers', 'fk_playback_transfers_session_id_user_id', 'session_id', 'listening_sessions', 'CASCADE'],
        ['playback_transfers', 'fk_playback_transfers_source_incarnation_id_user_id', 'source_incarnation_id', 'playback_destination_incarnations', null],
        ['playback_transfers', 'fk_playback_transfers_target_incarnation_id_user_id', 'target_incarnation_id', 'playback_destination_incarnations', null],
        ['playback_commands', 'fk_playback_commands_session_id_user_id', 'session_id', 'listening_sessions', 'CASCADE'],
        ['playback_commands', 'fk_playback_commands_incarnation_id_user_id', 'incarnation_id', 'playback_destination_incarnations', null],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        foreach (self::FOREIGN_KEYS as [$table, $name, $column, $foreignTable, $onDelete]) {
            // A focused SchemaTool fixture maps only some tables; a table that would own a key can be absent from it.
            if (!$schema->hasTable($table)) {
                continue;
            }

            $schema->getTable($table)->addForeignKeyConstraint(
                $foreignTable,
                [$column, 'user_id'],
                ['id', 'user_id'],
                $onDelete === null ? [] : ['onDelete' => $onDelete],
                $name,
            );
        }
    }
}
