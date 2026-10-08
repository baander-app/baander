<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Cancelling a cancelled job succeeds without change; a completed or failed job is a conflict. */
#[AsMessageHandler]
final readonly class CancelRecommendationJobHandler
{
    public function __construct(
        private RecommendationJobPortInterface $jobs,
        private RecommendationJobFinder $finder,
    ) {
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException when the job completed or failed
     */
    public function __invoke(CancelRecommendationJobCommand $command): void
    {
        $job = $this->finder->byPublicId($command->publicId);

        if ($job->isFinished()) {
            throw new ConflictException(
                sprintf('The recommendation job is %s and can no longer be cancelled.', $job->getStatus()->value),
                ['status' => $job->getStatus()->value],
            );
        }

        $job->markCancelled();
        $this->jobs->save($job);
    }
}
