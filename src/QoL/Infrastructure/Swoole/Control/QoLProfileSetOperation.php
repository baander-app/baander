<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole\Control;

use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use InvalidArgumentException;

/**
 * Sets the algorithm profile and answers with the worker's status.
 *
 * The profile store is shared and saved to its own file, so one worker's change
 * already reaches the others; running in every worker confirms each one reads the
 * new profile and names any worker that did not answer. Repeating it is harmless.
 */
final readonly class QoLProfileSetOperation implements ServerControlOperation
{
    public const string NAME = 'qol.profile.set';

    public function __construct(
        private StreamGovernor $governor,
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

    /**
     * @param array{profile?: mixed} $payload
     *
     * @return array<string, mixed> the worker's status after the change
     */
    public function handle(array $payload): array
    {
        $name = is_string($payload['profile'] ?? null) ? $payload['profile'] : '';
        $profile = AlgorithmProfile::tryFrom($name)
            ?? throw new InvalidArgumentException(sprintf('unknown QoL profile "%s"', $name));

        $this->governor->setProfile($profile);

        return $this->status->handle([]);
    }
}
