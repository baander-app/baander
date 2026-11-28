<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\DTO\WatchedMarkMapping;
use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use LogicException;

final readonly class WatchedMarkMove implements WatchedMarkMovePortInterface
{
    public function __construct(private Connection $connection, private WatchedMarkRepositoryInterface $marks) {}

    public function move(Uuid $operationId, array $videos, array $retainedEpisodeIds, array $mergedEpisodes = []): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Watched marks must move inside the placement transaction.');
        }
        // DQL has no conflict fence; the durable receipt and all mark writes share the placement commit.
        if ($this->connection->executeStatement(
            'INSERT INTO watched_mark_moves (operation_id) VALUES (?) ON CONFLICT (operation_id) DO NOTHING',
            [$operationId],
            ['uuid'],
        ) === 0) {
            return;
        }
        $sources = [];
        foreach ($videos as $video) {
            foreach ($video->beforeEpisodeIds as $episodeId) {
                $sources[$episodeId->toString()] = $episodeId;
            }
        }
        foreach ($mergedEpisodes as $merge) {
            $sources[$merge->sourceEpisodeId->toString()] = $merge->sourceEpisodeId;
        }
        $mapping = new WatchedMarkMapping($this->marks->findByEpisodeIds(array_values($sources)), $videos, $retainedEpisodeIds, $mergedEpisodes);
        foreach ($mapping->removedMarks as $mark) {
            $this->marks->delete($mark);
        }
        foreach ($mapping->marks as $mark) {
            $this->marks->save($mark);
        }
    }
}
