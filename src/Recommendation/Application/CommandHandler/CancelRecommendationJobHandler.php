<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
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

        if ($job->getStatus() === RecommendationJobStatus::Cancelled) {
            return;
        }
        if ($job->isFinished()) {
            throw self::finished($job->getStatus());
        }

        $job->markCancelled();
        // A run that completed or failed after the job was read keeps its outcome.
        if ($this->jobs->saveIfStatusIn($job, RecommendationJobStatus::Pending, RecommendationJobStatus::InProgress)) {
            return;
        }

        $stored = $this->jobs->storedStatus($job->getId())
            ?? throw new NotFoundException('Recommendation job not found.', ['publicId' => $command->publicId]);
        if ($stored !== RecommendationJobStatus::Cancelled) {
            throw self::finished($stored);
        }
    }

    private static function finished(RecommendationJobStatus $status): ConflictException
    {
        return new ConflictException(
            sprintf('The recommendation job is %s and can no longer be cancelled.', $status->value),
            ['status' => $status->value],
        );
    }
}
