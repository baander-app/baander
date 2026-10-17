<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\ExecuteRecommendationJobCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ExecuteRecommendationJobHandler
{
    public function __construct(private GenerateRecommendationsHandler $generator)
    {
    }

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(ExecuteRecommendationJobCommand $command): RecommendationGenerationResult
    {
        return $this->generator->execute($command->jobId);
    }
}
