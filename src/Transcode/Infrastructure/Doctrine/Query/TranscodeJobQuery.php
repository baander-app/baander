<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Query;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeJobDto;
use App\Transcode\Application\Query\TranscodeJobQueryPort;
use Doctrine\DBAL\Connection;

final class TranscodeJobQuery implements TranscodeJobQueryPort
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function findByPublicId(PublicId $publicId): ?TranscodeJobDto
    {
        $row = $this->connection->executeQuery(
            'SELECT * FROM transcode_jobs WHERE public_id = :publicId',
            ['publicId' => $publicId->toString()],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return TranscodeJobDto::fromArray($row);
    }

    public function findByUser(Uuid $userId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT j.* FROM transcode_jobs j'
            . ' INNER JOIN transcode_sessions s ON s.job_id = j.id'
            . ' WHERE s.user_id = :userId'
            . ' GROUP BY j.id ORDER BY j.updated_at DESC',
            ['userId' => $userId->toString()],
        )->fetchAllAssociative();

        return array_map(fn (array $row): TranscodeJobDto => TranscodeJobDto::fromArray($row), $rows);
    }

    public function findActiveByUser(Uuid $userId): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT j.* FROM transcode_jobs j"
            . " INNER JOIN transcode_sessions s ON s.job_id = j.id"
            . " WHERE s.user_id = :userId AND j.status IN ('pending', 'in_progress')"
            . " GROUP BY j.id ORDER BY j.updated_at DESC",
            ['userId' => $userId->toString()],
        )->fetchAllAssociative();

        return array_map(fn (array $row): TranscodeJobDto => TranscodeJobDto::fromArray($row), $rows);
    }
}
