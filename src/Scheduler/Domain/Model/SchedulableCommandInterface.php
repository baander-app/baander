<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

/**
 * Marker interface for Messenger commands that may be scheduled.
 *
 * Any bounded context that wants its commands to appear in the scheduler
 * admin panel must implement this interface on the command DTO.
 *
 * The SchedulerRegistry auto-discovers tagged implementations.
 */
interface SchedulableCommandInterface
{
    /**
     * Human-readable description of what this command does.
     * Displayed in the admin panel when selecting a command.
     */
    public static function schedulerDescription(): string;

    /**
     * Parameter schema for the admin panel.
     *
     * Returns an array of parameter definitions that describe the expected
     * constructor arguments. Used for both UI rendering and validation.
     *
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    public static function schedulerParameters(): array;
}
