<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Containment
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Containment qualification takes no arguments');
        Runtime::require(is_file($root . '/vendor/autoload.php'), 'Install Composer dependencies first');
        $runtime = new Runtime($root);
        $resources = new Resources($runtime, 'containment');
        $docker = $resources->docker;
        try {
            foreach (['term', 'kill'] as $scenario) {
                $name = $resources->id . '-' . $scenario;

                $container = $resources->createContainer($name, [
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
                    '--entrypoint',
                    'php',
                    $runtime->image('application')->id,
                    '/tmp/tests/Fixtures/Worker/contained-supervisor.php',
                ]);
                self::stageStopped($resources, $container);
                $docker->run(['start', $container]);
                $runtime->await(
                    fn(): bool => $docker->run(
                        ['exec', $container, 'test', '-s', '/tmp/baander-descendant-ready'],
                        check: false,
                    )->exitCode === 0,
                    5,
                    .1,
                    'Worker descendant failed to become ready',
                );
                $descendant = $docker->run(['exec', $container, 'cat', '/tmp/baander-descendant-ready'])->stdout
                    |> trim(...);
                $docker->run(['exec', $container, 'test', '-d', '/proc/' . $descendant]);
                $docker->run(['kill', '--signal', strtoupper($scenario), $container]);
                $runtime->await(
                    fn(): bool => $docker->run([
                        'inspect',
                        '--format',
                        '{{.State.Running}} {{.State.Pid}}',
                        $container,
                    ])->stdout
                        |> trim(...) === 'false 0',
                    5,
                    .1,
                    'Containment boundary did not stop before deadline',
                );
                Runtime::require(
                    $docker->run(['inspect', '--format', '{{.State.ExitCode}}', $container])->stdout
                        |> trim(...) === ($scenario === 'term' ? '0' : '137'),
                    'Unexpected containment exit code',
                );
                Runtime::require(
                    $docker->run(['inspect', '--format', '{{.State.OOMKilled}}', $container])->stdout
                        |> trim(...) === 'false',
                    'Containment unit was OOM killed',
                );
                if ($scenario === 'term') {
                    Runtime::require(
                        in_array(
                            'supervisor_direct_children_drained',
                            explode(
                                "\n",
                                $docker->run([
                                    'logs',
                                    $container,
                                ])->stdout
                                    |> trim(...),
                            ),
                            true,
                        ),
                        'Missing direct-child drain evidence',
                    );
                }
                Runtime::require(
                    $docker->run(['exec', $container, 'true'], check: false)->exitCode !== 0,
                    'Stopped worker still accepts processes',
                );
                $first = $resources->artifacts . '/' . $scenario . '-before';
                $second = $resources->artifacts . '/' . $scenario . '-after';
                $docker->run(['cp', $container . ':/tmp/baander-descendant-heartbeat', $first]);
                $runtime->pause(.3);
                $docker->run(['cp', $container . ':/tmp/baander-descendant-heartbeat', $second]);
                Runtime::require(
                    file_get_contents($first) === file_get_contents($second),
                    'Stopped descendant continued its heartbeat',
                );
                echo 'PASS: ', $scenario, " shutdown contained the worker and its descendant\n";
            }
            return 0;
        } finally {
            $resources->close();
        }
    }

    public static function stageStopped(Resources $resources, string $container): void
    {
        $archive = $resources->artifacts . '/checkout.tar';
        try {
            $resources->runtime->run([
                'tar',
                '-cf',
                $archive,
                'vendor',
                'packages',
                'src',
                'tests/Fixtures/Worker',
            ]);
            $extract = $resources->artifacts . '/staged';
            if (!is_dir($extract)) {
                mkdir($extract, 0700);
            }
            $resources->runtime->run(['tar', '-xf', $archive, '-C', $extract]);
            $resources->docker->run(['cp', $extract . '/.', $container . ':/tmp/']);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
            $previous = $resources->runtime->cleaning;
            $resources->runtime->cleaning = true;
            try {
                $resources->runtime->run(['rm', '-rf', '--', $resources->artifacts . '/staged']);
            } finally {
                $resources->runtime->cleaning = $previous;
            }
        }
    }
}
