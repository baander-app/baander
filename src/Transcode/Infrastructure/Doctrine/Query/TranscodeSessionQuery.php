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
            'SELECT * FROM transcode_sessions WHERE public_id = :publicId',
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
            'SELECT * FROM transcode_sessions WHERE user_id = :userId ORDER BY updated_at DESC',
            ['userId' => $userId->toString()],
        )->fetchAllAssociative();

        return array_map(fn (array $row): TranscodeSessionDto => TranscodeSessionDto::fromArray($row), $rows);
    }
}
