<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final readonly class CompletionPolicy
{
    /** @param list<int>|null $acceptedExitCodes Null permits any normal exit, but never timeout or interruption. */
    public function __construct(public ?array $acceptedExitCodes = [0]) {}

    public function complete(ProcessResult $result, ?ProcessSpec $command = null): ProcessResult
    {
        $status = match (true) {
            $result->timedOut => 124,
            $result->cancelled => 128 + ($result->requestedSignal ?? $result->signal ?? 15),
            $result->signal !== null => 128 + $result->signal,
            $this->acceptedExitCodes === null || in_array($result->exitCode, $this->acceptedExitCodes, true) => null,
            default => $result->exitCode > 0 ? $result->exitCode : 1,
        };
        if ($status === null) {
            return $result;
        }
        $reason = match (true) {
            $result->timedOut => 'timed out',
            $result->cancelled || $result->signal !== null => 'was interrupted',
            default => 'exited with status ' . $result->exitCode,
        };
        $name = $command === null ? 'Command' : basename($command->argv[0]);
        $diagnostic = substr($result->stdout, -4000) . substr($result->stderr, -4000)
            |> (static fn(string $output): string => substr($output, -4000));
        $logs = array_filter([$command?->logPath, $command?->stderrLogPath]);
        throw new CommandFailure(
            $name . ' ' . $reason . '.'
            . ($logs === [] ? '' : ' See ' . implode(', ', $logs) . '.')
            . ($diagnostic === '' ? '' : "\n" . $diagnostic),
            $status,
            $result,
        );
    }
}
