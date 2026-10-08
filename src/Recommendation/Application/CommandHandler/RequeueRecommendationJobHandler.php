<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\RequeueRecommendationJobCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Creates the new job and dispatches it, so it runs like a generated one: on the CPU
 * process pool in the web server, in this process elsewhere.
 */
#[AsMessageHandler]
final readonly class RequeueRecommendationJobHandler
{
    public function __construct(
        private RecommendationJobPortInterface $jobs,
        private RecommendationJobFinder $finder,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException when the job has not failed and was not cancelled
     */
    public function __invoke(RequeueRecommendationJobCommand $command): RecommendationGenerationResult
    {
        $original = $this->finder->byPublicId($command->publicId);

        if (!$original->canBeRequeued()) {
            throw new ConflictException(
                sprintf('Only a failed or cancelled recommendation job can be requeued; this one is %s.', $original->getStatus()->value),
                ['status' => $original->getStatus()->value],
            );
        }

        $originalPublicId = $original->getPublicId()->toString();
        $metadata = $original->getMetadata();
        $metadata['requeued_from'] = $originalPublicId;
        $metadata['requeued_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $metadata['requeued_by'] = $command->actor;
        $metadata['requeued_reason'] = $original->getFailReason() ?? 'manual_requeue';
        if (isset($metadata['original_job_public_id'])) {
            // Track the chain of requeues
            $metadata['requeue_chain'] = $metadata['requeue_chain'] ?? [];
            $metadata['requeue_chain'][] = $metadata['original_job_public_id'];
        }
        $metadata['original_job_public_id'] = $originalPublicId;

        $job = $this->jobs->create(
            isFull: $original->isFull(),
            userId: $original->getUserId(),
            metadata: $metadata,
            originalJobId: $original->getId(),
        );

        $result = $this->commandBus->dispatch(new GenerateRecommendationsCommand(
            mode: $original->isFull() ? GenerateRecommendationsCommand::MODE_FULL : GenerateRecommendationsCommand::MODE_INCREMENTAL,
            userId: $original->getUserId(),
            actor: $command->actor,
            jobId: $job->getId(),
        ))->last(HandledStamp::class)?->getResult();
        assert($result instanceof RecommendationGenerationResult);

        return $result;
    }
}
