<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Query\ListRecommendationJobsQuery;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ListRecommendationJobsHandler
{
    public function __construct(
        private RecommendationJobPortInterface $jobs,
    ) {
    }

    /**
     * @return list<RecommendationJob>
     *
     * @throws InvalidInputException for an unknown status
     */
    public function __invoke(ListRecommendationJobsQuery $query): array
    {
        $status = null;
        if ($query->status !== null) {
            $status = RecommendationJobStatus::tryFrom($query->status) ?? throw new InvalidInputException(
                sprintf(
                    'Unknown recommendation job status "%s". Use one of: %s.',
                    $query->status,
                    implode(', ', array_map(static fn (RecommendationJobStatus $case): string => $case->value, RecommendationJobStatus::cases())),
                ),
                ['status' => $query->status],
            );
        }

        $limit = min(ListRecommendationJobsQuery::MAX_LIMIT, max(1, $query->limit));

        return array_values($this->jobs->findRecent($limit, $status?->value));
    }
}
