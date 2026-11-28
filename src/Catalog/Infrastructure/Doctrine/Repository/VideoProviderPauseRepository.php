<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\VideoProviderPause;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class VideoProviderPauseRepository implements VideoProviderPauseRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function find(string $provider): ?VideoProviderPause
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM video_provider_pauses WHERE provider = ?', [$provider]);

        return $row === false ? null : $this->toDomain($row);
    }

    public function pause(string $provider, string $failureKind): VideoProviderPause
    {
        // DQL has no upsert; the conflict lock serializes failure counts and their database-clock retry.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO video_provider_pauses AS existing (provider, failure_kind, failure_count, retry_at)
                VALUES (:provider, :failure, 1, clock_timestamp() + interval '5 minutes')
                ON CONFLICT (provider) DO UPDATE
                SET failure_kind = EXCLUDED.failure_kind,
                    failure_count = LEAST(existing.failure_count + 1, 4),
                    retry_at = clock_timestamp() + CASE existing.failure_count WHEN 1 THEN interval '15 minutes' ELSE interval '1 hour' END
                RETURNING *
                SQL,
            ['provider' => $provider, 'failure' => $failureKind],
        );
        if ($row === false) {
            throw new \LogicException('A provider pause upsert must return its row.');
        }

        return $this->toDomain($row);
    }

    public function clear(string $provider): void
    {
        $this->connection->executeStatement('DELETE FROM video_provider_pauses WHERE provider = ?', [$provider]);
    }

    public function activePauses(): array
    {
        return array_map($this->toDomain(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM video_provider_pauses WHERE retry_at > clock_timestamp() ORDER BY retry_at, provider',
        ));
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): VideoProviderPause
    {
        return new VideoProviderPause(
            (string) $row['provider'],
            (string) $row['failure_kind'],
            (int) $row['failure_count'],
            $this->connection->convertToPHPValue($row['retry_at'], Types::DATETIME_IMMUTABLE)
                ?? throw new \UnexpectedValueException('A provider pause has no retry time.'),
        );
    }
}
