<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole\Control;

use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\StreamAllocation;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;

/** The streams one HTTP worker has admitted, with their predicted cost. Runs in every worker. */
final readonly class QoLStreamsOperation implements ServerControlOperation
{
    public const string NAME = 'qol.streams';

    public function __construct(
        private StreamGovernor $governor,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return true;
    }

    /** @return list<array{job_id: string, quality_tier: string, predicted_cost: float}> */
    public function handle(array $payload): array
    {
        return array_map(
            static fn (StreamAllocation $stream): array => [
                'job_id' => $stream->jobId->toString(),
                'quality_tier' => $stream->qualityTier,
                'predicted_cost' => round($stream->predictedCost, 2),
            ],
            $this->governor->getActiveStreams(),
        );
    }
}
