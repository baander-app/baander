<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Shared\Domain\Model\Uuid;

interface ScheduledJobAdministrationInterface
{
    /** @return ScheduledJob[] */
    public function findAll(): array;

    public function getById(Uuid $id): ?ScheduledJob;

    public function createJob(ScheduledJobInput $input): ScheduledJob;

    public function updateJob(Uuid $id, ScheduledJobInput $input): ?ScheduledJob;

    public function deleteById(Uuid $id): bool;

    public function pause(Uuid $id): ?ScheduledJob;

    public function resume(Uuid $id): ?ScheduledJob;

    public function enable(Uuid $id): ?ScheduledJob;

    public function disable(Uuid $id): ?ScheduledJob;

    /**
     * @return array{
     *     messenger: array<string, array{
     *         description: string,
     *         parameters: array<string, array{
     *             type: string,
     *             required: bool,
     *             description?: string,
     *             default?: mixed
     *         }>
     *     }>,
     *     console: array<string, array{
     *         description: string,
     *         parameters: array<string, array{
     *             type: string,
     *             required: bool,
     *             description?: string,
     *             default?: mixed
     *         }>
     *     }>
     * }
     */
    public function availableCommands(): array;
}
