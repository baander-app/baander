<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use App\QoL\Infrastructure\Swoole\Control\QoLLearningResetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLProfileSetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStatusOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStreamsOperation;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * Stands in for the server control channel in front of several HTTP workers, each
 * with its own governor and the real qol.* operations, sharing one profile store
 * and one state directory as the workers of a server do.
 */
final class QoLWorkers implements ServerControlPortInterface
{
    /** @var array<int, StreamGovernor> */
    public array $governors = [];

    /** @var list<int> workers that do not answer */
    public array $missing = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    public readonly AlgorithmProfileTable $profiles;

    /** @var array<int, array<string, ServerControlOperation>> */
    private array $operations = [];

    public function __construct(public readonly string $stateDir, int $workers = 3)
    {
        $this->profiles = new AlgorithmProfileTable($stateDir, new NullLogger());
        $fingerprint = new class implements EncoderProfileFingerprintPortInterface {
            public function getName(): string
            {
                return 'software';
            }
        };
        $ladder = new class implements QualityLadderPortInterface {
            public function defaultTiers(): array
            {
                return [];
            }

            public function defaultTierNames(): array
            {
                return [];
            }
        };
        for ($id = 0; $id < $workers; $id++) {
            $governor = new StreamGovernor(new LearningModel(), $ladder, $this->profiles);
            $persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $stateDir, new JsonEncoder());
            $status = new QoLStatusOperation($governor);
            $this->governors[$id] = $governor;
            foreach ([
                $status,
                new QoLStreamsOperation($governor),
                new QoLProfileSetOperation($governor, $status),
                new QoLLearningResetOperation($governor, $persister, $status),
            ] as $operation) {
                $this->operations[$id][$operation->name()] = $operation;
            }
        }
    }

    public function execute(string $operation, array $payload = []): ServerControlResult
    {
        $this->calls[] = [$operation, $payload];
        $results = [];
        $errors = [];
        foreach ($this->operations as $id => $operations) {
            if (in_array($id, $this->missing, true)) {
                continue;
            }
            $handler = $operations[$operation] ?? throw new ServerControlException(sprintf('unknown server control operation "%s"', $operation));
            try {
                $results[$id] = $handler->handle($payload);
            } catch (Throwable $exception) {
                $errors[$id] = $exception->getMessage();
            }
        }

        return new ServerControlResult($results, $errors, $this->missing);
    }
}
