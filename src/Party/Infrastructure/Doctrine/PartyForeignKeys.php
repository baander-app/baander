<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/**
 * Constraints on party columns mapped as scalar IDs: the owners from Version001_InitialSchema,
 * the event session and actor from Version20261006280000, and the session's video and transcode
 * job from Version20261006310000.
 */
final class PartyForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_party_sessions_host_user_id', 'party_sessions', 'host_user_id', 'users', 'id', 'CASCADE');
        // Deleting a video ends its parties; members and events follow through their own cascades.
        yield new ForeignKeyDeclaration('fk_party_sessions_video_id', 'party_sessions', 'video_id', 'videos', 'id', 'CASCADE');
        // Deleting a job, for example in orphaned-job cleanup, leaves the party running without one.
        yield new ForeignKeyDeclaration('fk_party_sessions_transcode_job_id', 'party_sessions', 'transcode_job_id', 'transcode_jobs', 'id', 'SET NULL');
        yield new ForeignKeyDeclaration('fk_party_members_user_id', 'party_members', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_party_events_session_id', 'party_events', 'session_id', 'party_sessions', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_party_events_user_id', 'party_events', 'user_id', 'users', 'id', 'CASCADE');
    }
}
