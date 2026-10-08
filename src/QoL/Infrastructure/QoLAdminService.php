<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Infrastructure\Swoole\Control\QoLLearningResetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLProfileSetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStatusOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStreamsOperation;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;

/**
 * Runs QoL administration through the server control channel, so the admin API
 * (in-process, inside an HTTP worker) and the app:qol:* commands (through the
 * control socket) reach every worker's governor the same way.
 *
 * @phpstan-import-type StatusReport from QoLAdminPortInterface
 * @phpstan-import-type StreamsReport from QoLAdminPortInterface
 * @phpstan-import-type WorkerStatus from QoLAdminPortInterface
 * @phpstan-import-type WorkerStreams from QoLAdminPortInterface
 * @phpstan-import-type WorkerError from QoLAdminPortInterface
 */
final readonly class QoLAdminService implements QoLAdminPortInterface
{
    public function __construct(
        private ServerControlPortInterface $serverControl,
    ) {
    }

    public function getStatus(): array
    {
        return $this->statusReport($this->serverControl->execute(QoLStatusOperation::NAME));
    }

    public function getActiveStreams(): array
    {
        $result = $this->serverControl->execute(QoLStreamsOperation::NAME);
        $workers = [];
        $count = 0;
        $cost = 0.0;
        foreach ($result->results as $workerId => $streams) {
            /** @var list<array{job_id: string, quality_tier: string, predicted_cost: float}> $streams */
            $streams = is_array($streams) ? array_values($streams) : [];
            $workers[] = ['worker_id' => $workerId, 'active_streams' => count($streams), 'streams' => $streams];
            $count += count($streams);
            $cost += array_sum(array_column($streams, 'predicted_cost'));
        }

        return [
            'workers' => $workers,
            'total' => ['active_streams' => $count, 'predicted_cost' => round($cost, 2)],
            'missing_workers' => $result->missingWorkers,
            'worker_errors' => $this->errors($result),
        ];
    }

    public function setProfile(string $profile): array
    {
        $algorithmProfile = AlgorithmProfile::tryFrom($profile);
        if ($algorithmProfile === null) {
            $allowed = sprintf('Must be one of: %s.', implode(', ', array_column(AlgorithmProfile::cases(), 'value')));

            $message = $profile === '' ? 'A profile is required.' : sprintf('Unknown profile "%s".', $profile);

            throw new InvalidInputException(sprintf('%s %s', $message, $allowed), ['profile' => [$allowed]]);
        }

        return $this->statusReport(
            $this->serverControl->execute(QoLProfileSetOperation::NAME, ['profile' => $algorithmProfile->value]),
        );
    }

    public function resetLearning(): array
    {
        return $this->statusReport($this->serverControl->execute(QoLLearningResetOperation::NAME));
    }

    /** @return StatusReport */
    private function statusReport(ServerControlResult $result): array
    {
        $workers = [];
        $active = 0;
        foreach ($result->results as $workerId => $status) {
            /** @var WorkerStatus $row */
            $row = ['worker_id' => $workerId] + (is_array($status) ? $status : []);
            $workers[] = $row;
            $active += $row['active_streams'];
        }

        return [
            'workers' => $workers,
            'total' => ['active_streams' => $active],
            'missing_workers' => $result->missingWorkers,
            'worker_errors' => $this->errors($result),
        ];
    }

    /** @return list<WorkerError> */
    private function errors(ServerControlResult $result): array
    {
        $errors = [];
        foreach ($result->errors as $workerId => $error) {
            $errors[] = ['worker_id' => $workerId, 'error' => $error];
        }

        return $errors;
    }
}
