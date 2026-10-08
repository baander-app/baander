<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole\Control;

use App\QoL\Domain\Service\StreamGovernor;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;

/**
 * One HTTP worker's governor: learning state, profile, active streams and model.
 * Runs in every worker, because each worker admits streams and learns on its own.
 */
final readonly class QoLStatusOperation implements ServerControlOperation
{
    public const string NAME = 'qol.status';

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

    /** @return array{state: string, profile: string, active_streams: int, sample_count: int, model_ready: bool, budget_cap: float} */
    public function handle(array $payload): array
    {
        $model = $this->governor->getModel();
        $profile = $this->governor->getProfile();

        return [
            'state' => $this->governor->getState()->value,
            'profile' => $profile->value,
            'active_streams' => $this->governor->getActiveStreamCount(),
            'sample_count' => $model->sampleCount(),
            'model_ready' => $model->isReady(),
            'budget_cap' => $profile->budgetCap(),
        ];
    }
}
