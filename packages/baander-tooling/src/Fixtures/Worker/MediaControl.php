<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class MediaControl
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if (getenv('BAANDER_TEST_MEDIA_COST') === 'hardware') {
            return MediaCostHardware::main($root);
        }
        if (($arguments[0] ?? null) === 'verify-load') {
            return MediaLoadVerifier::main(array_slice($arguments, 1));
        }
        $load = getenv('BAANDER_TEST_MEDIA_LOAD') ?: '';
        if ($arguments === []) {
            $runtime = new Runtime($root, budget: $load !== '' ? 'workerMediaLoad' : 'workerCapacity');
            $artifacts = Runtime::artifacts($root, match (true) {
                $load !== '' => 'media-load-' . $load,
                getenv('BAANDER_TEST_MEDIA_COST') === '1' => 'media-cost',
                default => 'media-control',
            });
            try {
                $runtime->run(
                    [PHP_BINARY, 'scripts/test-worker-operator-container.php'],
                    env: array_replace(
                        Checkpoint::operatorEnvironment($runtime, $artifacts),
                        $load === '' ? [] : [DrillProfile::ENVIRONMENT => getenv(DrillProfile::ENVIRONMENT) ?: MediaLoad::profilePath($load)],
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
        if (getenv('BAANDER_TEST_MEDIA_COST') === '1') {
            return self::cost(new Checkpoint($arguments, $root, 600), $root);
        }
        if ($load !== '') {
            return MediaLoad::run(new Checkpoint($arguments, $root, 2000), $load);
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
            try {
                $runtime->wait($client);
            } catch (\Throwable $error) {
                try {
                    $runtime->cleaning = true;
                    $runtime->docker()->run(
                        ['exec', $checkpoint->execution, 'php', 'tests/Fixtures/Worker/MediaControlObservation.php', 'diagnose'],
                        timeout: 5,
                        logPath: $checkpoint->artifacts . '/failed-client-publication.json',
                        stderrLogPath: $checkpoint->artifacts . '/failed-client-publication.stderr',
                    );
                } catch (\Throwable $diagnosticError) {
                    fwrite(STDERR, 'Media publication diagnostic failed: ' . $diagnosticError->getMessage() . "\n");
                }
                throw $error;
            }
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

    /** Measure per-job native and media child cost without the control drill's CPU pressure. */
    private static function cost(Checkpoint $checkpoint, string $root): int
    {
        $runtime = $checkpoint->runtime;
        try {
            $checkpoint->inspectBefore();
            $services = [];
            foreach (['web' => $checkpoint->web, 'postgres' => $checkpoint->postgres, 'redis' => preg_replace('/-postgres$/D', '-redis', $checkpoint->postgres),
                'control' => $checkpoint->control, 'execution' => $checkpoint->execution] as $role => $container) {
                $services[] = $role . '=' . trim($checkpoint->docker->run(['inspect', '--format', '{{.Id}}', $container])->stdout);
            }
            $checkpoint->measurements->host(
                [PHP_BINARY, $root . '/tests/Fixtures/Worker/media-cost-host-sample.php', '580', ...$services],
                'cost-host-samples.jsonl',
                'cost-host-sampler.log',
                585,
            );
            foreach (['control' => $checkpoint->control, 'execution' => $checkpoint->execution] as $unit => $container) {
                $checkpoint->measurements->container(
                    ['exec', $container, 'php', 'tests/Fixtures/Worker/media-cost-sample.php', '580', $unit],
                    'cost-' . $unit . '-samples.jsonl',
                    'cost-' . $unit . '-sampler.log',
                    585,
                );
            }
            $client = $checkpoint->background(
                ['exec', $checkpoint->web, 'php', '-d', 'memory_limit=1G', 'tests/Fixtures/TwoCommand/deployment.php', 'media-cost'],
                'media-cost-client.log',
                'media-cost-client.log.stderr',
                570,
            );
            $runtime->wait($client);
            // The media role admits only its configured slots; replaying the recorded
            // encoder measures the per-encode cost at the larger target slot count.
            $encoder = getenv('BAANDER_TEST_MEDIA_COST_ENCODER') ?: 'libx264';
            $perEncoder = (int) ceil(MediaCost::peakNativeRssMiB($checkpoint->artifacts . '/cost-execution-samples.jsonl', 'video-encode') * 1.15);
            $checkpoint->capture(
                ['exec', $checkpoint->execution, 'php', 'tests/Fixtures/Worker/media-cost-replay.php', '8', '45', $encoder, (string) max($perEncoder, 64)],
                'media-cost-replay.json',
                75,
            );
            $checkpoint->measurements->finish();
            $checkpoint->copy(
                $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/result-media-cost.json'),
                'media-cost-client.json',
            );
            $checkpoint->copy(
                $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/native-launches.jsonl'),
                'native-launches.jsonl',
            );
            $checkpoint->inspectAfter();
            $receipt = MediaCost::receipt($checkpoint->artifacts);
            Reports::publish($checkpoint->artifacts . '/media-cost.json', $receipt);
            echo json_encode($receipt['summary'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
            return 0;
        } finally {
            $runtime->close();
        }
    }
}
