<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;

interface ScheduledConsoleExecutorInterface
{
    /**
     * Execute an already authorized console command in an isolated CLI child.
     * The adapter limits output capture and PHP heap and enforces a run deadline.
     * OS-stalled calls and descendant containment require deployment-level recovery.
     * @param array<array-key, mixed> $parameters
     * @throws ScheduledConsoleCompletionUnknown If execution or descendant completion is uncertain; contain the deployment before recovery.
     */
    public function execute(string $command, array $parameters): string;
}
