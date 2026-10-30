<?php

declare(strict_types=1);

$configuration = json_decode(file_get_contents($_SERVER['argv'][1]), true, flags: JSON_THROW_ON_ERROR);
$arguments = array_slice($_SERVER['argv'], 2);
if (($configuration['mode'] ?? 'git') === 'docker') {
    file_put_contents($configuration['calls'], json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    if (in_array('compose', $arguments, true) && ($configuration['hang-command'] ?? false)) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($configuration): never {
            file_put_contents($configuration['marker'] . '.stopped', '0');
            exit(0);
        });
        file_put_contents($configuration['marker'], 'started');
        $deadline = hrtime(true) / 1_000_000_000 + 10;
        while (hrtime(true) / 1_000_000_000 < $deadline) {
            usleep(10000);
        }
    }
    if (in_array('info', $arguments, true)) {
        echo '{"ID":"boundary-daemon"}';
    }
    exit(0);
}
if (($arguments[0] ?? null) === 'rev-parse' && ($arguments[1] ?? null) === '--show-toplevel') {
    if ($configuration['hang'] ?? false) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($configuration): never {
            file_put_contents($configuration['marker'] . '.stopped', '0');
            exit(0);
        });
        file_put_contents($configuration['marker'], (string) getmypid());
        $deadline = hrtime(true) / 1_000_000_000 + 2;
        while (hrtime(true) / 1_000_000_000 < $deadline) {
            usleep(10000);
        }
    }
    echo $configuration['repository'];
} elseif (($arguments[0] ?? null) === 'remote') {
    echo 'https://forgejo.baander.app/baander/baander.git';
} elseif (($arguments[0] ?? null) === 'ls-files' && ($configuration['hang-snapshot'] ?? false)) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($configuration): never {
        file_put_contents($configuration['marker'] . '.stopped', '0');
        exit(0);
    });
    file_put_contents($configuration['marker'], (string) getmypid());
    $deadline = hrtime(true) / 1_000_000_000 + 10;
    while (hrtime(true) / 1_000_000_000 < $deadline) {
        usleep(10000);
    }
} elseif (($arguments[0] ?? null) !== 'ls-files') {
    fwrite(STDERR, 'Unexpected disposable git fixture command');
    exit(2);
}
