<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

/**
 * Marker interface for Symfony console commands that may be scheduled.
 *
 * Implement this on console command classes that should be available
 * in the scheduler admin panel. The SchedulerRegistry auto-discovers
 * tagged implementations.
 */
interface SchedulableConsoleCommandInterface
{
    public function getName(): ?string;

    public function getDescription(): string;

    /**
     * Parameter schema for the admin panel.
     *
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    public static function schedulerParameters(): array;
}
