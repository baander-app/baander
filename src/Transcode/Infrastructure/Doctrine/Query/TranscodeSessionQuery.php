<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Query;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use App\Transcode\Application\Query\TranscodeSessionQueryPort;
use Doctrine\DBAL\Connection;

final class TranscodeSessionQuery implements TranscodeSessionQueryPort
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function findByPublicId(PublicId $publicId): ?TranscodeSessionDto
    {
        $row = $this->connection->executeQuery(
            'SELECT s.*, u.public_id AS user_public_id, j.public_id AS job_public_id, v.public_id AS video_public_id'
            . ' FROM transcode_sessions s JOIN users u ON u.id = s.user_id'
            . ' JOIN transcode_jobs j ON j.id = s.job_id JOIN videos v ON v.id = s.video_id'
            . ' WHERE s.public_id = :publicId',
            ['publicId' => $publicId->toString()],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return TranscodeSessionDto::fromArray($row);
    }

    public function findByUser(Uuid $userId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT s.*, u.public_id AS user_public_id, j.public_id AS job_public_id, v.public_id AS video_public_id'
            . ' FROM transcode_sessions s JOIN users u ON u.id = s.user_id'
            . ' JOIN transcode_jobs j ON j.id = s.job_id JOIN videos v ON v.id = s.video_id'
            . ' WHERE s.user_id = :userId ORDER BY s.updated_at DESC',
            ['userId' => $userId->toString()],
        )->fetchAllAssociative();

        return array_map(fn (array $row): TranscodeSessionDto => TranscodeSessionDto::fromArray($row), $rows);
    }
}
