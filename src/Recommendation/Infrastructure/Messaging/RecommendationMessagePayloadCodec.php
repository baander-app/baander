<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Messaging;

use App\Recommendation\Application\Command\ExecuteRecommendationJobCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class RecommendationMessagePayloadCodec implements MessagePayloadCodecInterface
{
    public static function types(): array
    {
        return ['recommendation.execute_job' => ExecuteRecommendationJobCommand::class];
    }

    public function fields(string $type): array
    {
        return match ($type) {
            'recommendation.execute_job' => ['job_id'],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof ExecuteRecommendationJobCommand => [$message->jobId->toString()],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'recommendation.execute_job' => new ExecuteRecommendationJobCommand(Uuid::fromString($p['job_id'])),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
