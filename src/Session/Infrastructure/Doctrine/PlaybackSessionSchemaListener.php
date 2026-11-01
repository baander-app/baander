<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class PlaybackSessionSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('listening_sessions')) {
            return;
        }
        $table = $schema->getTable('listening_sessions');
        $table->addForeignKeyConstraint('playback_destinations', ['selected_destination_id', 'user_id'], ['id', 'user_id'], [], 'fk_playback_selected_destination');
        $table->addForeignKeyConstraint('playback_destination_incarnations', ['active_incarnation_id', 'user_id'], ['id', 'user_id'], [], 'fk_playback_active_incarnation');
    }
}
