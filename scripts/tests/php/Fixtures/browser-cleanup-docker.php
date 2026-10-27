#!/usr/bin/env php
<?php

declare(strict_types=1);

$scenario = getenv('CLEANUP_TEST_SCENARIO');
$removed = (string) getenv('CLEANUP_TEST_REMOVED');
$args = array_slice($argv ?? [], 1);
if (array_slice($args, 0, 2) === ['container', 'ls']) {
    if ($scenario === 'daemon-failure') {
        exit(1);
    }
    if ($scenario !== 'never-created' && !file_exists($removed)) {
        echo "baander-browser-cleanup-test\n";
    }
} elseif (array_slice($args, 0, 2) === ['rm', '-f']) {
    if (in_array($scenario, ['daemon-failure', 'survivor'], true)) {
        exit(1);
    }
    touch($removed);
} elseif ($args[0] === 'cp') {
    exit($scenario === 'copy-failure' ? 1 : 0);
} else {
    exit(99);
}
