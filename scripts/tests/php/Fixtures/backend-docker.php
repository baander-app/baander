#!/usr/bin/env php
<?php

declare(strict_types=1);

$directory = getenv('BACKEND_FAKE_ROOT');
$arguments = array_slice($_SERVER['argv'], 1);
file_put_contents(
    $directory . '/commands.jsonl',
    json_encode($arguments, JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);
if (in_array($arguments[0] ?? '', ['--context', '--host'], true)) {
    $arguments = array_slice($arguments, 2);
}
$statePath = $directory . '/state.json';
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR) : ['containers' => [], 'projects' => []];
$save = static function () use (&$state, $statePath): void {
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
};
$failure = getenv('BACKEND_FAIL_STAGE') ?: '';
$copy = static function (string $source, string $target) use ($directory): void {
    $resolve = static function (string $path) use ($directory): string {
        if (preg_match('/^([^:]+):(.*)$/', $path, $match) === 1) {
            return $directory . '/containers/' . $match[1] . $match[2];
        }
        return $path;
    };
    $source = $resolve($source);
    $target = $resolve($target);
    $transfer = static function (string $from, string $to) use (&$transfer): void {
        if (is_dir($from)) {
            if (!is_dir($to)) {
                mkdir($to, 0700, true);
            }
            foreach (new DirectoryIterator($from) as $entry) {
                if (!$entry->isDot()) {
                    $transfer($entry->getPathname(), $to . '/' . $entry->getFilename());
                }
            }
        } else {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0700, true);
            }
            copy($from, $to);
        }
    };
    $transfer($source, $target);
};
$command = $arguments[0] ?? '';
if ($command === 'context' && ($arguments[1] ?? '') === 'show') {
    echo 'fixture-current';
    exit(0);
}
if ($command === 'info') {
    file_put_contents(
        $directory . '/docker-environment.json',
        json_encode(
            [
                'tls' => getenv('DOCKER_TLS_VERIFY'),
                'certificates' => getenv('DOCKER_CERT_PATH'),
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
    echo '{"ID":"fixture-daemon"}';
    exit(0);
}
if ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    $state['containers'][$name] = true;
    $save();
    mkdir($directory . '/containers/' . $name . '/tmp/baander-run', 0700, true);
    exit(0);
}
if ($command === 'compose') {
    if (($arguments[1] ?? '') === 'version') {
        echo "Docker Compose fixture\n";
        exit(0);
    }
    $project = $arguments[array_search('--project-name', $arguments, true) + 1];
    if (in_array('down', $arguments, true)) {
        unset($state['projects'][$project]);
        $save();
        exit($failure === 'cleanup' ? 7 : 0);
    }
    if (in_array('logs', $arguments, true)) {
        echo "compose log\n";
        exit(0);
    }
    if (in_array('ps', $arguments, true)) {
        $service = $arguments[array_key_last($arguments)];
        $name = substr(hash('sha256', $project . $service), 0, 12);
        if (!is_dir($directory . '/containers/' . $name . '/tmp/baander-run')) {
            mkdir($directory . '/containers/' . $name . '/tmp/baander-run', 0700, true);
        }
        echo $name;
        exit(0);
    }
    $state['projects'][$project] = true;
    $save();
    exit(0);
}
if ($command === 'cp') {
    $copy($arguments[1], $arguments[2]);
    exit(0);
}
if ($command === 'exec') {
    $position = array_search('scripts/backend-test-runner.php', $arguments, true);
    if ($position === false) {
        if (array_any(
            $arguments,
            static fn(string $argument): bool => str_contains($argument, 'doctrine:migrations:migrate'),
        )) {
            echo "service setup retained\n";
        }
        exit(0);
    }
    $app = $arguments[3];
    $mode = $arguments[$position + 1];
    if ($failure === 'cancel' && $mode === 'discover') {
        echo "before cancellation\n";
        $fields = file_get_contents('/proc/self/stat')
            |> (static fn(string $stat): string => substr($stat, strrpos($stat, ')') + 2))
            |> (static fn(string $stat): array => explode(' ', $stat));
        Swoole\Process::kill((int) $fields[1], 15);
        usleep(2_000_000);
    }
    if ($failure === $mode) {
        fwrite(STDERR, $mode . ' fixture failure');
        exit(5);
    }
    $run = $directory . '/containers/' . $app . '/tmp/baander-run';
    if ($mode === 'discover') {
        $files = ['tests/Unit/FixtureTest.php' => ['FixtureTest::testWorks']];
        if (getenv('BACKEND_SERVICES') === '1') {
            $files = [
                'tests/Functional/FirstTest.php' => ['FirstTest::testWorks'],
                'tests/Integration/SecondTest.php' => ['SecondTest::testWorks'],
            ];
        }
        file_put_contents(
            $run . '/discovery.json',
            json_encode(['files' => $files, 'coverage' => false, 'runId' => 'fixture', 'reports' => []], JSON_THROW_ON_ERROR),
        );
    } elseif ($mode === 'run') {
        $lane = $arguments[$position + 3];
        file_put_contents(
            $run . '/' . $lane . '.result.json',
            json_encode(['status' => 0, 'identities' => ['FixtureTest::testWorks']], JSON_THROW_ON_ERROR),
        );
        echo "lane output retained\n";
    }
    exit(0);
}
if ($command === 'rm') {
    unset($state['containers'][$arguments[array_key_last($arguments)]]);
    $save();
    exit($failure === 'cleanup' ? 7 : 0);
}
if ($command === 'logs') {
    echo "container log\n";
    exit(0);
}
if ($command === 'inspect') {
    echo '[{"Mounts":[{"Type":"volume","Name":"anonymous-fixture-volume"}]}]';
    exit(0);
}
if (in_array($command, ['ps', 'network', 'volume'], true)) {
    if ($failure === 'inventory' && $command === 'volume') {
        fwrite(STDERR, "inventory fixture failure\n");
        exit(8);
    }
    $filter = array_search('--filter', $arguments, true);
    if ($filter !== false) {
        if (str_starts_with($arguments[$filter + 1], 'label=com.baander.backend.run=')) {
            echo implode("\n", array_keys($state['containers']));
            exit(0);
        }
        $project = substr($arguments[$filter + 1], strlen('label=com.docker.compose.project='));
        if (isset($state['projects'][$project]) && $command === 'ps') {
            echo substr(hash('sha256', $project . 'app'), 0, 12);
        }
    }
    exit(0);
}
exit(0);
