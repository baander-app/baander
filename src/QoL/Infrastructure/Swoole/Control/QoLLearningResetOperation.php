<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole\Control;

use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;

/**
 * Resets the worker's learning model, returns it to the Learning state, clears its
 * active streams and saves the reset at once, so a reload does not bring the old
 * model back. Runs in every worker; answers with the worker's status.
 */
final readonly class QoLLearningResetOperation implements ServerControlOperation
{
    public const string NAME = 'qol.learning.reset';

    public function __construct(
        private StreamGovernor $governor,
        private LearningDataPersister $persister,
        private QoLStatusOperation $status,
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

    /** @return array<string, mixed> the worker's status after the reset */
    public function handle(array $payload): array
    {
        $this->governor->resetLearning();
        $this->persister->save();

        return $this->status->handle([]);
    }
}
