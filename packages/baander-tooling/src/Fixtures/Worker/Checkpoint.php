<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\RunningProcess;

final readonly class Checkpoint
{
    public Runtime $runtime;
    public Docker $docker;
    public string $artifacts;
    public string $control;
    public string $execution;
    public string $postgres;
    public string $database;
    public string $web;

    /** @param list<string> $arguments */
    public function __construct(array $arguments, string $root, float $seconds)
    {
        if (count($arguments) !== 6) {
            throw new \InvalidArgumentException('Expected artifacts control execution ipc-volume postgres database');
        }
        [$this->artifacts, $this->control, $this->execution, , $this->postgres, $this->database] = $arguments;
        $this->web = preg_replace('/-postgres$/D', '-web', $this->postgres);
        $this->runtime = new Runtime($root, $seconds);
        $this->docker = $this->runtime->docker(true);
        if (!is_dir($this->artifacts)) {
            mkdir($this->artifacts, 0700, true);
        }
    }

    /** @param list<string> $arguments */
    public function capture(array $arguments, string $name, float $timeout = 12, bool $check = true): string
    {
        $result = $this->docker->run($arguments, timeout: $timeout, check: false);
        file_put_contents($this->artifacts . '/' . $name, $result->stdout);
        if ($result->stderr !== '') {
            file_put_contents($this->artifacts . '/' . $name . '.log', $result->stderr);
        }
        Runtime::require(
            !$check || ($result->exitCode === 0 && !$result->timedOut && !$result->cancelled),
            'Checkpoint command failed; see ' . $this->artifacts . '/' . $name,
        );
        return $result->stdout;
    }

    /** @param list<string> $arguments */
    public function background(array $arguments, string $output, string $errors, float $seconds): RunningProcess
    {
        return $this->runtime->start(
            [...$this->docker->commandPrefix(), ...$arguments],
            timeout: $seconds,
            logPath: $this->artifacts . '/' . $output,
            stderrLogPath: $this->artifacts . '/' . $errors,
            terminationGrace: 3,
        );
    }

    public function pressureReady(RunningProcess $process): void
    {
        $this->runtime->await(
            function () use ($process): bool {
                Runtime::require($this->runtime->alive($process), 'Pressure fixture exited before it became ready');
                $path = $this->artifacts . '/pressure.log';
                return is_file($path) && str_contains(file_get_contents($path), 'heldAudioIdentities');
            },
            5,
            .05,
            'Pressure fixture did not establish occupied slots',
        );
    }

    public function mediaReady(RunningProcess $client, float $seconds = 30): void
    {
        $this->runtime->await(
            function () use ($client): bool {
                Runtime::require($this->runtime->alive($client), 'Media client exited before it became ready');
                return $this->docker->run(
                    ['exec', $this->web, 'test', '-f', 'storage/two-command/capacity-ready'],
                    check: false,
                )->exitCode === 0;
            },
            $seconds,
            .2,
            'Media client did not become ready',
        );
    }

    public function inspectBefore(): void
    {
        $this->capture(
            [
                'inspect',
                $this->control,
                $this->execution,
                $this->postgres,
                preg_replace(
                    '/-postgres$/D',
                    '-redis',
                    $this->postgres,
                ),
                $this->web,
            ],
            'containers-before.json',
        );
    }

    public function inspectAfter(string $name = 'containers-after.json'): void
    {
        $this->capture(['inspect', $this->control, $this->execution], $name);
    }

    public function copy(string $source, string $name): void
    {
        $this->docker->run(['cp', $source, $this->artifacts . '/' . $name]);
    }

    /** @param array<string,string> $environment */
    public static function operator(
        Runtime $runtime,
        string $artifacts,
        array $environment,
        ?float $seconds = null,
    ): void {
        if (!is_dir(dirname($artifacts))) {
            mkdir(dirname($artifacts), 0700, true);
        }
        $runtime->run(
            [PHP_BINARY, 'scripts/test-worker-operator-container.php'],
            env: array_replace($runtime->environment, $environment, [
                'BAANDER_TEST_ARTIFACTS' => $artifacts,
            ]),
            timeout: $seconds,
            logPath: $artifacts . '.log',
            terminationGrace: 40,
        );
    }
}
