<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class MediaControl
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if ($arguments === []) {
            $runtime = new Runtime($root, budget: 'workerCapacity');
            $artifacts = Runtime::artifacts($root, 'media-control');
            try {
                $runtime->run(
                    [PHP_BINARY, 'scripts/test-worker-operator-container.php'],
                    env: array_replace(
                        Checkpoint::operatorEnvironment($runtime, $artifacts),
                        [
                            'BAANDER_TEST_ARTIFACTS' => $artifacts,
                            'BAANDER_TEST_TWO_COMMAND' => '1',
                            'BAANDER_TEST_AUTOSCALING_MAXIMUM' => '2',
                            'BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '1',
                            'BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => $root . '/scripts/test-worker-media-control-container.sh',
                        ],
                    ),
                    timeout: null,
                    logPath: $artifacts . '/operator.log',
                    terminationGrace: 40,
                );
                echo 'Media control evidence: ', $artifacts, "\n";
                return 0;
            } finally {
                $runtime->close();
            }
        }
        $checkpoint = new Checkpoint($arguments, $root, 300);
        $runtime = $checkpoint->runtime;
        $docker = $checkpoint->docker;
        try {
            $checkpoint->inspectBefore();
            $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->control,
                    'php',
                    'tests/Fixtures/Worker/control-capacity-sample.php',
                    '240',
                    'control',
                ],
                'control-samples.jsonl',
                'control-sampler.log',
                245,
            );
            $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/control-capacity-sample.php',
                    '240',
                    'execution',
                ],
                'execution-samples.jsonl',
                'execution-sampler.log',
                245,
            );
            $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/MediaControlObservation.php',
                    'monitor',
                    $runtime->layout()->path('storage/two-command/media-native-state.json'),
                    '240',
                ],
                'native-monitor.log',
                'native-monitor.log.stderr',
                245,
            );
            $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->web,
                    'php',
                    'tests/Fixtures/Worker/media-control-backlog.php',
                ],
                'backlog.jsonl',
                'backlog.log',
                245,
            );
            $client = $checkpoint->background(
                [
                    'exec',
                    $checkpoint->web,
                    'php',
                    'tests/Fixtures/TwoCommand/deployment.php',
                    'media-control',
                ],
                'media-client.log',
                'media-client.log.stderr',
                245,
            );
            $checkpoint->mediaReady($client, 60);
            $checkpoint->capture(['top', $checkpoint->execution, '-eo', 'pid,ppid,args'], 'occupied-slots.txt');
            $pressure = $checkpoint->background(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    '-d',
                    'memory_limit=-1',
                    'tests/Fixtures/Worker/control-capacity-pressure.php',
                    'cpu',
                    '180',
                ],
                'pressure.log',
                'pressure.log.stderr',
                185,
            );
            $checkpoint->pressureReady($pressure);
            $docker->run(['exec', $checkpoint->web, 'touch', 'storage/two-command/capacity-pressure-start']);
            $runtime->wait($client);
            $runtime->wait($pressure);
            $checkpoint->measurements->finish();
            $checkpoint->copy(
                $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/result-media-control.json'),
                'media.json',
            );
            $checkpoint->copy(
                $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/native-launches.jsonl'),
                'native-launches.jsonl',
            );
            $checkpoint->capture(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/MediaControlObservation.php',
                    'verify',
                ],
                'native-verification.json',
            );
            $checkpoint->inspectAfter();
            return MediaControlVerifier::main([$checkpoint->artifacts]);
        } finally {
            $runtime->close();
        }
    }
}
