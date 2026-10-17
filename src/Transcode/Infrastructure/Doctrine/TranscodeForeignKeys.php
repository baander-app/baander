<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Foreign keys for Transcode fields mapped as scalar identifiers. */
final class TranscodeForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_transcode_control_receipts_session_id', 'transcode_control_receipts', 'session_id', 'transcode_sessions', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_jobs_owner_user_id', 'transcode_jobs', 'owner_user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_runtime_state_job_id', 'transcode_runtime_state', 'job_id', 'transcode_jobs', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_runtime_state_session_id', 'transcode_runtime_state', 'session_id', 'transcode_sessions', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_sessions_user_id', 'transcode_sessions', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_jobs_video_id', 'transcode_jobs', 'video_id', 'videos', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_sessions_video_id', 'transcode_sessions', 'video_id', 'videos', 'id', 'CASCADE');
    }
}
