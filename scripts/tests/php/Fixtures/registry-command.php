#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'];
$name = basename($arguments[0]);
$args = array_slice($arguments, 1);
$state = getenv('REGISTRY_TEST_STATE');
file_put_contents($state . '/calls', $name . ' ' . implode(' ', $args) . "\n", FILE_APPEND);
if ($name === 'docker') {
    if (($args[0] ?? '') === 'rm' && in_array(getenv('STUB_FAIL'), ['cleanup', 'encrypted-cleanup'], true)) {
        exit(7);
    }
    if (($args[0] ?? '') === 'run') {
        $cadence = in_array('tests/run_backup_cadence_contract.php', $args, true);
        $encrypted = in_array('tests/run_backup_qualification.php', $args, true);
        if ($encrypted && in_array(getenv('STUB_FAIL'), ['encrypted', 'encrypted-cleanup'], true)) {
            exit(27);
        }
        if ($cadence && getenv('STUB_FAIL') === 'cadence') {
            exit(28);
        }
    }
} elseif ($name === 'curl') {
    if (getenv('STUB_FAIL') === 'download') {
        fwrite(STDERR, "injected download failure\n");
        exit(19);
    }
    touch($args[array_search('-o', $args, true) + 1]);
} elseif ($name === 'sha256sum') {
    $input = stream_get_contents(STDIN);
    if (!preg_match('/^[a-f0-9]{64}  /', $input)) {
        exit(8);
    }
} elseif ($name === 'git') {
    echo str_repeat('a', 40), "\n";
} elseif ($name === 'tar' && str_contains(implode(' ', $args), 'openssl.tar.gz')) {
    $source = $args[array_search('-C', $args, true) + 1] . '/openssl-3.5.3';
    mkdir($source);
    file_put_contents($source . '/Configure', "#!/bin/sh\nexit 0\n");
    chmod($source . '/Configure', 0755);
} elseif ($name === 'cmake' && in_array('--build', $args, true)) {
    $build = $args[1];
    if (!is_dir($build)) {
        mkdir($build);
    }
    copy(__FILE__, $build . '/registry_parser_fuzz');
    chmod($build . '/registry_parser_fuzz', 0755);
} elseif ($name === 'registry_parser_fuzz') {
    echo "parser output\n";
    if (getenv('STUB_FAIL') === 'fuzz') {
        $prefix = array_find($args, static fn(string $arg): bool => str_starts_with($arg, '-artifact_prefix='));
        file_put_contents(substr($prefix, strlen('-artifact_prefix=')) . 'crash-input', 'input');
        exit(29);
    }
} elseif ($name === 'clang++') {
    $target = $args[array_search('-o', $args, true) + 1];
    file_put_contents($target, "#!/bin/sh\necho 'AddressSanitizer: heap-use-after-free'\nexit 1\n");
    chmod($target, 0755);
} elseif (str_starts_with($name, 'run_') || str_starts_with($name, 'test_')) {
    if (in_array($name, ['run_cluster_contract.php', 'run_restore_contract.php'], true)) {
        if (($position = array_search('--diagnostics', $args, true)) !== false) {
            $directory = $args[$position + 1];
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory . '/node-0.log', "process diagnostics\n");
        }
        if (getenv('STUB_FAIL') === 'cluster' && $name === 'run_cluster_contract.php') {
            echo "injected cluster failure\n";
            exit(23);
        }
        if (in_array(getenv('STUB_FAIL'), ['terminate', 'stubborn'], true) && $name === 'run_cluster_contract.php') {
            if (getenv('STUB_FAIL') === 'stubborn') {
                Swoole\Process::signal(15, static function (): void {});
            }
            $storage = sys_get_temp_dir() . '/qualification-child-' . bin2hex(random_bytes(4));
            mkdir($storage);
            file_put_contents($state . '/storage', $storage);
            file_put_contents($state . '/child', (string) getmypid());
            $deadline = microtime(true) + 60;
            while (microtime(true) < $deadline) {
                usleep(10000);
            }
        }
    }
    echo 'PASS: ', $name, "\n";
}
