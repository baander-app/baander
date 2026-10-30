<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Retirement
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Retirement qualification takes no arguments');
        Runtime::require(is_file($root . '/vendor/autoload.php'), 'Install Composer dependencies first');
        $runtime = new Runtime($root);
        $resources = new Resources($runtime, 'retirement');
        $docker = $resources->docker;
        $boot = str_repeat('a', 32);
        try {

            $container = $resources->createContainer($resources->id, [
                '--network',
                'none',
                '--memory',
                '256m',
                '--memory-swap',
                '256m',
                '--cpus',
                '1',
                '--pids-limit',
                '32',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--cgroupns',
                'private',
                '--restart',
                'always',
                '--label',
                'app.baander.worker.namespace=baander.app:retirement-test',
                '--label',
                'app.baander.worker.boot-id=' . $boot,
                '--label',
                'app.baander.worker.role=execution',
                '--entrypoint',
                'php',
                $runtime->image('application')->id,
                '/tmp/tests/Fixtures/Worker/contained-supervisor.php',
            ]);
            Containment::stageStopped($resources, $container);
            $docker->run(['start', $container]);
            $runtime->await(
                fn(): bool => $docker->run(
                    [
                        'exec',
                        $container,
                        'test',
                        '-s',
                        '/tmp/baander-descendant-ready',
                    ],
                    check: false,
                )->exitCode === 0,
                5,
            );
            $endpoint = $docker->target->endpoint;
            $command = [
                PHP_BINARY,
                'tests/Fixtures/Worker/retire-container.php',
                $docker->target->executable,
                $endpoint,
                $container,
            ];
            Runtime::require(
                $runtime->run(
                    [...$command, str_repeat('b', 32)],
                    check: false,
                    logPath: $resources->artifacts . '/rejected.log',
                )->exitCode !== 0,
                'Wrong owner retired deployment',
            );
            Runtime::require(
                $docker->run(['inspect', '--format', '{{.State.Running}}', $container])->stdout
                    |> trim(...) === 'true',
                'Wrong owner stopped deployment',
            );
            Runtime::require(
                $runtime->run(
                    [...$command, $boot],
                    check: false,
                    logPath: $resources->artifacts . '/restart-rejected.log',
                )->exitCode !== 0,
                'Restart-always unit satisfied isolation policy',
            );
            Runtime::require(
                $docker->run(['inspect', '--format', '{{.State.Running}}', $container])->stdout
                    |> trim(...) === 'true',
                'Rejected restart policy stopped deployment',
            );
            $docker->run(['update', '--restart=no', $container]);
            $runtime->run([...$command, $boot]);
            Runtime::require(
                $docker->run(['start', $container], check: false)->exitCode !== 0,
                'Retired predecessor could restart',
            );
            Runtime::require(
                $runtime->run(
                    [...$command, $boot],
                    check: false,
                    logPath: $resources->artifacts . '/absent.log',
                )->exitCode !== 0,
                'Absent predecessor treated as retirement receipt',
            );

            echo "PASS: wrong-owner and restart-policy rejection and running deployment retirement with restart prevention\n";
            return 0;
        } finally {
            $resources->close();
        }
    }
}
