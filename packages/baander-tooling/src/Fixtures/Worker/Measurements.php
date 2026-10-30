<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\RunningProcess;

final class Measurements
{
    /** @var list<array{process:RunningProcess,container:?string,stopFile:string,output:string}> */
    private array $producers = [];

    public function __construct(private readonly Runtime $runtime, private readonly Client $docker, private readonly string $artifacts) {}

    /** @param list<string> $arguments Docker exec followed by the container and sampler command. */
    public function container(array $arguments, string $output, string $errors, float $seconds): RunningProcess
    {
        Runtime::require(($arguments[0] ?? null) === 'exec' && isset($arguments[1]), 'Measurement producer requires a container exec command');
        $stopFile = '/tmp/baander-measurement-' . bin2hex(random_bytes(16)) . '.stop';
        $process = $this->docker->start(
            ['exec', '-e', 'BAANDER_TEST_SAMPLER_STOP_FILE=' . $stopFile, ...array_slice($arguments, 1)],
            timeout: $seconds,
            logPath: $this->artifacts . '/' . $output,
            stderrLogPath: $this->artifacts . '/' . $errors,
            terminationGrace: 3,
        );
        $this->producers[] = ['process' => $process, 'container' => $arguments[1], 'stopFile' => $stopFile, 'output' => $output];
        return $process;
    }

    /** @param non-empty-list<string> $arguments */
    public function host(array $arguments, string $output, string $errors, float $seconds): RunningProcess
    {
        $stopFile = $this->artifacts . '/measurement-' . bin2hex(random_bytes(16)) . '.stop';
        $process = $this->runtime->start(
            $arguments,
            env: array_replace($this->runtime->environment, ['BAANDER_TEST_SAMPLER_STOP_FILE' => $stopFile]),
            timeout: $seconds,
            logPath: $this->artifacts . '/' . $output,
            stderrLogPath: $this->artifacts . '/' . $errors,
            terminationGrace: 3,
        );
        $this->producers[] = ['process' => $process, 'container' => null, 'stopFile' => $stopFile, 'output' => $output];
        return $process;
    }

    public function finish(?ContainedSamplerDrain $drain = null): void
    {
        foreach ($this->producers as $producer) {
            // A contained pair has already stopped; its exec wrappers may still be draining output.
            if ($drain !== null && $producer['container'] !== null) {
                continue;
            }
            if (!$this->runtime->alive($producer['process'])) {
                continue;
            }
            if ($producer['container'] === null) {
                Runtime::require(file_put_contents($producer['stopFile'], '') === 0, 'Cannot request final measurement capture');
            } else {
                $local = $this->artifacts . '/' . basename($producer['stopFile']);
                Runtime::require(file_put_contents($local, '') === 0, 'Cannot request final measurement capture');
                $this->docker->run(['cp', $local, $producer['container'] . ':' . $producer['stopFile']]);
            }
        }
        $completed = [];
        foreach ($this->producers as $producer) {
            $completed[] = $this->runtime->wait($producer['process'], check: $drain === null || $producer['container'] === null);
        }
        foreach ($this->producers as $index => $producer) {
            if ($drain !== null && $producer['container'] !== null) {
                $drain->complete($producer['container'], $producer['output'], $completed[$index]);
            }
        }
        $this->producers = [];
    }
}
