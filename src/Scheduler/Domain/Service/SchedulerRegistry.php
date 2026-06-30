<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Service;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface;

/**
 * Registry of schedulable commands (messenger + console).
 *
 * Collects implementations via tagged iterator and provides
 * lookup methods for validation and admin UI rendering.
 */
class SchedulerRegistry
{
    /** @var array<class-string<SchedulableCommandInterface>, SchedulableCommandInterface> */
    private array $messengerCommands = [];

    /** @var array<string, SchedulableConsoleCommandInterface> commandName => instance */
    private array $consoleCommands = [];

    /**
     * @param iterable<SchedulableCommandInterface> $messengerCommandProviders
     * @param iterable<SchedulableConsoleCommandInterface> $consoleCommandProviders
     */
    public function __construct(
        private readonly iterable $messengerCommandProviders,
        private readonly iterable $consoleCommandProviders,
    ) {
    }

    /**
     * Initialize the registry from tagged iterators.
     * Called lazily on first access.
     */
    private function initialize(): void
    {
        if ($this->messengerCommands !== [] || $this->consoleCommands !== []) {
            return;
        }

        foreach ($this->messengerCommandProviders as $command) {
            $this->messengerCommands[$command::class] = $command;
        }

        foreach ($this->consoleCommandProviders as $command) {
            $name = $command->getName();
            if ($name !== null) {
                $this->consoleCommands[$name] = $command;
            }
        }
    }

    public function isMessengerCommandAllowed(string $fqcn): bool
    {
        $this->initialize();

        return isset($this->messengerCommands[$fqcn]);
    }

    public function isConsoleCommandAllowed(string $commandName): bool
    {
        $this->initialize();

        return isset($this->consoleCommands[$commandName]);
    }

    /**
     * @return array<class-string<SchedulableCommandInterface>, array{description: string, parameters: array}>
     */
    public function getMessengerCommands(): array
    {
        $this->initialize();

        $result = [];
        foreach ($this->messengerCommands as $fqcn => $command) {
            $result[$fqcn] = [
                'description' => $fqcn::schedulerDescription(),
                'parameters' => $fqcn::schedulerParameters(),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{description: string, parameters: array}>
     */
    public function getConsoleCommands(): array
    {
        $this->initialize();

        $result = [];
        foreach ($this->consoleCommands as $name => $command) {
            $result[$name] = [
                'description' => $command->getDescription() ?? $name,
                'parameters' => $command::schedulerParameters(),
            ];
        }

        return $result;
    }

    /**
     * Get the parameter schema for a messenger command.
     *
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    public function getMessengerParameterSchema(string $fqcn): array
    {
        $this->initialize();

        if (!isset($this->messengerCommands[$fqcn])) {
            return [];
        }

        return $fqcn::schedulerParameters();
    }

    /**
     * Get the parameter schema for a console command.
     *
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    public function getConsoleParameterSchema(string $commandName): array
    {
        $this->initialize();

        if (!isset($this->consoleCommands[$commandName])) {
            return [];
        }

        return $this->consoleCommands[$commandName]::schedulerParameters();
    }
}
