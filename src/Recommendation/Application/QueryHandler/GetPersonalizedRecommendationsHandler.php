<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Query\GetPersonalizedRecommendationsQuery;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class GetPersonalizedRecommendationsHandler
{
    private const STRATEGY_LABELS = [
        'collaborative' => 'Users with similar taste liked this',
        'content' => 'Similar sound and mood',
        'genre' => 'Same genre style',
        'database' => 'From the same album',
    ];

    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
        private readonly SongRepositoryInterface $songRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetPersonalizedRecommendationsQuery $query): array
    {
        $userId = $query->getUserId();
        $limit = $query->getLimit();

        // Get all recommendations for this user (with higher limit to aggregate)
        $allRecommendations = $this->recommendationRepository->findForUser($userId, $limit * 10);

        // Aggregate by target item
        $aggregated = [];
        foreach ($allRecommendations as $rec) {
            if ((string) $rec->getTargetType() !== $query->getTargetType()) {
                continue;
            }

            $targetId = $rec->getTargetId();
            $strategy = $rec->getName();

            if (!isset($aggregated[$targetId])) {
                $aggregated[$targetId] = [
                    'target_id' => $targetId,
                    'strategies' => [],
                    'total_score' => 0,
                ];
            }

            $aggregated[$targetId]['strategies'][$strategy] = $rec->getScore();
            $aggregated[$targetId]['total_score'] += $rec->getScore();
        }

        // Sort by total score and take top N
        uasort($aggregated, fn ($a, $b) => $b['total_score'] <=> $a['total_score']);
        $topItems = array_slice($aggregated, 0, $limit, true);

        // Fetch catalog data for recommended items
        $itemIds = array_map(fn ($item) => $item['target_id'], array_keys($topItems));
        $songs = $this->songRepository->findByUuids(
            array_map(fn ($id) => new \App\Shared\Domain\Model\Uuid($id), $itemIds)
        );

        // Build enriched response
        $result = [];
        foreach ($topItems as $targetId => $data) {
            $song = $songs[$targetId] ?? null;
            if ($song === null) {
                continue;
            }

            // Get top contributing strategy for one-line explanation
            arsort($data['strategies']);
            $topStrategy = array_key_first($data['strategies']);
            $topScore = $data['strategies'][$topStrategy];

            $result[] = [
                'target_id' => $targetId,
                'target_type' => $query->getTargetType(),
                'total_score' => round($data['total_score'], 3),
                'explanation' => self::STRATEGY_LABELS[$topStrategy] ?? 'Recommended for you',
                'strategies' => $data['strategies'],
                'song' => $this->songToArray($song),
            ];
        }

        return $result;
    }

    private function songToArray(\App\Catalog\Domain\Model\Song $song): array
    {
        return [
            'id' => $song->getId()->toString(),
            'public_id' => $song->getPublicId()->toString(),
            'title' => $song->getTitle(),
            'album_id' => $song->getAlbumId()->toString(),
            'track' => $song->getTrack(),
            'disc' => $song->getDisc(),
            'year' => $song->getYear(),
            'length' => $song->getLength(),
        ];
    }
}
