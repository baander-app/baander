#!/usr/bin/env php
<?php

declare(strict_types=1);
$arguments = array_slice($_SERVER['argv'], 1);
$input = stream_get_contents(STDIN);
file_put_contents(
    getenv('WEB_RUNTIME_CALLS'),
    json_encode(
        ['binary' => basename($_SERVER['argv'][0]), 'arguments' => $arguments, 'input' => $input],
        JSON_THROW_ON_ERROR,
    ) . "\n",
    FILE_APPEND | LOCK_EX,
);
if (basename($_SERVER['argv'][0]) === 'tar') {
    $index = array_search('-cf', $arguments, true);
    file_put_contents($arguments[$index + 1], 'checkout-fixture');
    chmod($arguments[$index + 1], 0600);
    exit(0);
}
if ($arguments === ['context', 'show']) {
    echo "web-fixture\n";
    exit(0);
}
require __DIR__ . '/WebDockerState.php';
if ($arguments[0] !== 'context') {
    array_splice($arguments, 0, 2);
}
webDockerState($arguments, getenv('WEB_RUNTIME_CALLS'));
$mode = getenv('WEB_RUNTIME_MODE');
if ($arguments[0] === 'cp') {
    if ((fileperms($arguments[1]) & 0777) !== 0644 || (fileperms(dirname($arguments[1])) & 0777) !== 0700) {
        fwrite(STDERR, 'Checkout must be private on the host and readable after copying to the app.');
        exit(61);
    }
}
if ($arguments[0] === 'network' && $arguments[1] === 'create' && $mode === 'early-failure') {
    fwrite(STDERR, 'network-characterization-failure');
    exit(37);
}
if ($arguments[0] === 'rm' || $arguments[0] === 'network' && $arguments[1] === 'rm') {
    exit(in_array($mode, ['cleanup-failure', 'fixture-failure'], true) ? 53 : 0);
}
if (in_array('redis-cli', $arguments, true)) {
    echo "PONG\n";
}
if (in_array('http://127.0.0.1:9200/api/server', $arguments, true)) {
    echo '{"server":{"settings":{"task_worker_num":0}}}';
}
if (in_array('safari', $arguments, true)) {
    if ($mode === 'safari-mismatch') {
        echo "wrong-baseline\n";
    } else {
        echo file_get_contents(getenv('WEB_RUNTIME_ROOT') . '/tests/Fixtures/WebRuntime/safari-in-progress.txt');
    }
}
if (in_array('pgrep', $arguments, true)) {
    if (in_array('-o', $arguments, true)) {
        echo "10\n";
    } elseif (in_array('-f', $arguments, true)) {
        echo "20\n";
    } else {
        $state = getenv('WEB_RUNTIME_CALLS') . '.workers';
        $calls = is_file($state) ? (int) file_get_contents($state) : 0;
        file_put_contents($state, (string) ($calls + 1));
        echo $calls === 0 ? "31\n32\n" : "41\n42\n";
    }
}
if (in_array('Health degradation detected', $arguments, true)) {
    echo "Health degradation detected {\"component\":\"postgresql\"}\nHealth degradation detected {\"component\":\"redis\"}\n";
}
if (in_array('psql', $arguments, true)) {
    echo "web-runtime-admin@baander.app\nweb-runtime-super-admin@baander.app\n";
}
if (in_array('tail', $arguments, true)) {
    echo 'owned-serve-diagnostic';
}
if (in_array('security-runtime-secret-canary', $arguments, true)) {
    if ($mode === 'canary') {
        echo "security-runtime-secret-canary\n";
        exit(0);
    }
    exit(1);
}
if (in_array('tests/Fixtures/security-runtime.php', $arguments, true) && $mode === 'fixture-failure') {
    fwrite(STDERR, 'security-characterization-failure');
    exit(29);
}
