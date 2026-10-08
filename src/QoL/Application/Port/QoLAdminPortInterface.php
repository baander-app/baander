<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\ServerControlException;

/**
 * QoL administration of the running web server, for the admin API and the
 * app:qol:* commands alike.
 *
 * Each call reads or changes the stream governor in every HTTP worker through the
 * server control channel. A report lists one row per worker that answered, names
 * the workers that did not answer in `missing_workers` and those whose call failed
 * in `worker_errors`; a change with either is a partial failure.
 *
 * @phpstan-type WorkerError array{worker_id: int, error: string}
 * @phpstan-type WorkerStatus array{worker_id: int, state: string, profile: string, active_streams: int, sample_count: int, model_ready: bool, budget_cap: float}
 * @phpstan-type StatusReport array{workers: list<WorkerStatus>, total: array{active_streams: int}, missing_workers: list<int>, worker_errors: list<WorkerError>}
 * @phpstan-type Stream array{job_id: string, quality_tier: string, predicted_cost: float}
 * @phpstan-type WorkerStreams array{worker_id: int, active_streams: int, streams: list<Stream>}
 * @phpstan-type StreamsReport array{workers: list<WorkerStreams>, total: array{active_streams: int, predicted_cost: float}, missing_workers: list<int>, worker_errors: list<WorkerError>}
 */
interface QoLAdminPortInterface
{
    /**
     * @return StatusReport
     *
     * @throws ServerControlException when no web server runs in this container or it cannot answer
     */
    public function getStatus(): array;

    /**
     * @return StreamsReport
     *
     * @throws ServerControlException when no web server runs in this container or it cannot answer
     */
    public function getActiveStreams(): array;

    /**
     * Sets the algorithm profile in every worker and saves it. Setting the current profile again succeeds.
     *
     * @return StatusReport every worker's status after the change
     *
     * @throws InvalidInputException when the profile name is unknown; no worker is changed
     * @throws ServerControlException when no web server runs in this container or it cannot answer
     */
    public function setProfile(string $profile): array;

    /**
     * Resets the learning model in every worker, returns each to the Learning state and saves the reset.
     *
     * @return StatusReport every worker's status after the reset
     *
     * @throws ServerControlException when no web server runs in this container or it cannot answer
     */
    public function resetLearning(): array;
}
