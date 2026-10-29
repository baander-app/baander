<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Autoscaling
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if ($arguments === []) {
            $runtime = new Runtime($root, budget: 'workerCapacity');
            $artifacts = Runtime::artifacts($root, 'autoscaling');
            try {
                $runtime->run(
                    [PHP_BINARY, 'tests/Fixtures/Worker/autoscaling-verify.php', 'thresholds'],
                    logPath: $artifacts . '/thresholds.json',
                );
                foreach ([1, 2, 4] as $maximum) {
                    Checkpoint::operator(
                        $runtime,
                        $artifacts . '/' . $maximum,
                        [
                            'BAANDER_TEST_AUTOSCALING_MAXIMUM' => (string) $maximum,
                            'BAANDER_TEST_TWO_COMMAND' => '1',
                            'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => $root . '/scripts/test-worker-autoscaling-container.sh',
                        ],
                        600,
                    );
                }
                $runtime->run(
                    [PHP_BINARY, 'tests/Fixtures/Worker/autoscaling-verify.php', 'compare', $artifacts],
                    interactive: true,
                );
                echo 'Autoscaling evidence: ', $artifacts, "\n";
                return 0;
            } finally {
                $runtime->close();
            }
        }
        $checkpoint = new Checkpoint($arguments, $root, 480);
        $runtime = $checkpoint->runtime;
        $docker = $checkpoint->docker;
        $started = Runtime::now();
        $maximum = $runtime->environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? '';
        Runtime::require(
            preg_match('/^[1-9][0-9]*$/D', $maximum) === 1,
            'Set BAANDER_TEST_AUTOSCALING_MAXIMUM to a positive concurrency',
        );
        try {
            $runtime->run(
                [PHP_BINARY, 'tests/Fixtures/Worker/autoscaling-verify.php', 'thresholds'],
                logPath: $checkpoint->artifacts . '/thresholds.json',
            );
            $checkpoint->capture(['info', '--format', '{{json .}}'], 'daemon.json');
            $checkpoint->inspectBefore();
            $docker->run(
                [
                    'exec',
                    $checkpoint->control,
                    'php',
                    'tests/Fixtures/Worker/autoscaling-observation-gap.php',
                ],
                timeout: 25,
                logPath: $checkpoint->artifacts . '/gap.json',
                stderrLogPath: $checkpoint->artifacts . '/gap.log',
                terminationGrace: 2,
            );
            AutoscalingVerifier::main(['gap', $checkpoint->artifacts]);
            $control = $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->control,
                    'php',
                    'tests/Fixtures/Worker/autoscaling-sample.php',
                    'control',
                    '475',
                ],
                'control-samples.jsonl',
                'control-sampler.log',
                480,
            );
            $execution = $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/autoscaling-sample.php',
                    'execution',
                    '475',
                ],
                'execution-samples.jsonl',
                'execution-sampler.log',
                480,
            );
            $await = static function (string $mode, int $seconds, string $phase = '') use ($checkpoint, $runtime, $control, $execution, $started): void {
                $runtime->await(
                    static function () use ($checkpoint, $runtime, $control, $execution, $started, $mode, $phase): bool {
                        Runtime::require(
                            $runtime->alive($control) && $runtime->alive($execution),
                            'Autoscaling sampler exited',
                        );
                        Runtime::require(
                            Runtime::now() - $started < 470,
                            'Autoscaling callback exceeded qualification bound',
                        );
                        $result = $runtime->run(
                            [
                                PHP_BINARY,
                                'tests/Fixtures/Worker/autoscaling-verify.php',
                                $mode,
                                $checkpoint->artifacts,
                                $phase,
                            ],
                            check: false,
                            logPath: $checkpoint->artifacts . '/await-' . $mode . '.log',
                        );
                        return $result->exitCode === 0;
                    },
                    $seconds,
                    .5,
                    'Autoscaling evidence did not satisfy ' . $mode,
                );
            };
            $await('idle', 20);
            foreach (['cold', 'repeat'] as $batch) {
                $checkpoint->capture(
                    [
                        'exec',
                        $checkpoint->control,
                        'php',
                        'tests/Fixtures/Worker/autoscaling-dispatch.php',
                        $batch,
                    ],
                    $batch . '.json',
                );
                $await('burst', AutoscalingVerifier::THRESHOLDS['burstDeadlineSeconds'], $batch);
                $await('idle', AutoscalingVerifier::THRESHOLDS['idleDeadlineSeconds']);
            }
            $checkpoint->capture(
                [
                    'exec',
                    $checkpoint->control,
                    'php',
                    'tests/Fixtures/Worker/autoscaling-dispatch.php',
                    'cpu',
                    (string) AutoscalingVerifier::THRESHOLDS['cpuMessages'],
                    'cpu',
                ],
                'cpu.json',
            );
            $await('burst', AutoscalingVerifier::THRESHOLDS['cpuDeadlineSeconds'], 'cpu');
            $await('idle', AutoscalingVerifier::THRESHOLDS['idleDeadlineSeconds']);
            $client = $checkpoint->background(
                [
                    'exec',
                    $checkpoint->web,
                    'php',
                    'tests/Fixtures/TwoCommand/deployment.php',
                    'capacity',
                ],
                'media-client.log',
                'media-client.log.stderr',
                110,
            );
            $checkpoint->mediaReady($client);
            $pressure = $checkpoint->background(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/control-capacity-pressure.php',
                    'cpu',
                ],
                'pressure.log',
                'pressure.log.stderr',
                90,
            );
            $checkpoint->pressureReady($pressure);
            $docker->run(['exec', $checkpoint->web, 'touch', 'storage/two-command/capacity-pressure-start']);
            $runtime->wait($client);
            $runtime->wait($pressure);
            $checkpoint->copy(
                $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/result-capacity.json'),
                'media.json',
            );
            $await('idle', AutoscalingVerifier::THRESHOLDS['idleDeadlineSeconds']);
            $checkpoint->inspectAfter();
            Runtime::require(
                array_all(
                    Runtime::json($checkpoint->artifacts . '/containers-after.json'),
                    static fn(array $unit): bool => $unit['State']['Running'] === true,
                ),
                'Autoscaling qualification must leave pair running',
            );
            $checkpoint->measurements->finish();
            AutoscalingVerifier::main(['verify', $checkpoint->artifacts, $maximum]);
            file_put_contents(
                $checkpoint->artifacts . '/checkpoint-seconds.txt',
                (string) (int) (Runtime::now() - $started) . "\n",
            );
            return 0;
        } finally {
            $runtime->close();
        }
    }
}
