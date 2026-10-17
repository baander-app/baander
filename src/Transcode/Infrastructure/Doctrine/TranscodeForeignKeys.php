<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Constraints on transcode columns mapped as scalar IDs: the session owner and source video. */
final class TranscodeForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_transcode_sessions_user_id', 'transcode_sessions', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_jobs_video_id', 'transcode_jobs', 'video_id', 'videos', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_transcode_sessions_video_id', 'transcode_sessions', 'video_id', 'videos', 'id', 'CASCADE');
    }
}
