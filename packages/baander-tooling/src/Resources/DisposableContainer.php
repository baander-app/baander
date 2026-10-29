<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Process\{CompletionPolicy, ProcessResult};

/** A single attached container whose ownership survives lost creation or removal replies. */
final class DisposableContainer
{
    /** @param list<string> $arguments Docker create arguments, without lifecycle/name/ownership flags.
     * @param array<string,string> $environment
     * @param array<string,string> $containerEnvironment
     */
    public static function run(
        Client $docker,
        string $receipt,
        array $arguments,
        array $environment,
        float $cleanupSeconds,
        ?string $input = null,
        ?float $timeout = null,
        bool $check = true,
        ?string $logPath = null,
        array $containerEnvironment = [],
        ?string $stderrLogPath = null,
        float $terminationGrace = 0.25,
        bool $appendLog = false,
    ): ProcessResult {
        $operation = FileLock::acquire($receipt . '.operation.lock');
        try {
            if (file_exists($receipt) || is_link($receipt)) {
                throw new \RuntimeException('An unresolved disposable resource receipt must be recovered before retrying: ' . $receipt);
            }
            $name = 'baander-tooling-' . bin2hex(random_bytes(16));
            $ledger = new ResourceLedger($receipt, $docker, $environment);
            $result = null;
            $ledger->scoped(function () use ($docker, $ledger, $name, $arguments, $input, $timeout, $check, $logPath, $containerEnvironment, $stderrLogPath, $terminationGrace, $appendLog, &$result): int {
                $interactive = $input !== null || in_array('-i', $arguments, true) || in_array('--interactive', $arguments, true);
                if ($interactive && !in_array('-i', $arguments, true) && !in_array('--interactive', $arguments, true)) {
                    array_unshift($arguments, '-i');
                }
                $id = $ledger->create(ResourceKind::Container, $name, $arguments, $containerEnvironment);
                $start = ['start', '--attach', ...($interactive ? ['--interactive'] : []), $id];
                $attached = $docker->run($start, input: $input, timeout: $timeout, check: false, logPath: $logPath, stderrLogPath: $stderrLogPath, terminationGrace: $terminationGrace, appendLog: $appendLog);
                $status = $attached->exitCode;
                if ($status === 0) {
                    $observed = trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $id])->stdout);
                    if (preg_match('/^[0-9]{1,3}$/D', $observed) !== 1 || (int) $observed > 255) {
                        throw new \RuntimeException('Cannot confirm the disposable container exit status.');
                    }
                    $status = (int) $observed;
                }
                $result = new ProcessResult(
                    $status,
                    $attached->signal,
                    $attached->timedOut,
                    $attached->cancelled,
                    $attached->stdout,
                    $attached->stderr,
                    $attached->duration,
                    $attached->requestedSignal,
                );
                new CompletionPolicy($check ? [0] : null)->complete($result, $docker->command($start, logPath: $logPath, stderrLogPath: $stderrLogPath, terminationGrace: $terminationGrace, appendLog: $appendLog));
                return $result->exitCode;
            }, $cleanupSeconds);
            return $result ?? throw new \LogicException('Disposable container completed without a process result.');
        } finally {
            $operation->release();
        }
    }
}
