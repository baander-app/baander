<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeJobDto;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use Doctrine\DBAL\Connection;

final readonly class TranscodePublicView implements TranscodePublicViewInterface
{
    public function __construct(private Connection $connection, private TranscodeStreamingPortInterface $streaming, private StreamAuthPortInterface $streamAuth)
    {
    }

    public function videoId(PublicId $publicId): ?Uuid
    {
        $value = $this->connection->fetchOne('SELECT id FROM videos WHERE public_id = :publicId', ['publicId' => $publicId->toString()]);

        return is_string($value) ? Uuid::fromString($value) : null;
    }

    public function session(TranscodeSession $session): TranscodeSessionDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.public_id AS user_id, j.public_id AS job_id, v.public_id AS video_id'
            . ' FROM users u CROSS JOIN transcode_jobs j CROSS JOIN videos v'
            . ' WHERE u.id = :userId AND j.id = :jobId AND v.id = :videoId',
            ['userId' => $session->getUserId()->toString(), 'jobId' => $session->getJobId()->toString(), 'videoId' => $session->getVideoId()->toString()],
        );
        if ($row === false) {
            throw new NotFoundException('Session relationship not found.');
        }

        $playback = $this->streaming->getPlaybackState($session->getVideoId(), $row['job_id']);
        if ($playback !== null) {
            $path = '/api/transcode/'.$row['video_id'].'/master.m3u8?'.http_build_query(['generation' => $playback['generation'], 'job' => $playback['jobId']]);
            $playback['manifestUrl'] = $this->streamAuth->signUrl($path)['url'];
        }
        return TranscodeSessionDto::fromModel($session, PublicId::fromString($row['user_id']), PublicId::fromString($row['job_id']), PublicId::fromString($row['video_id']), $playback);
    }

    public function job(TranscodeJob $job): TranscodeJobDto
    {
        $value = $this->connection->fetchOne('SELECT public_id FROM videos WHERE id = :id', ['id' => $job->getVideoId()->toString()]);
        if (!is_string($value)) {
            throw new NotFoundException('Job video not found.');
        }

        return TranscodeJobDto::fromModel($job, PublicId::fromString($value));
    }
}
