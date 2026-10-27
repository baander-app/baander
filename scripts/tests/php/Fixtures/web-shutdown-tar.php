#!/usr/bin/env php
<?php

declare(strict_types=1);

$args = array_slice($_SERVER['argv'] ?? [], 1);
file_put_contents(
    getenv('WEB_WORKFLOW_TAR_TRACE'),
    json_encode($args, JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);
if (($args[0] ?? '') !== '-cf' || !isset($args[1])) {
    exit(2);
}
file_put_contents($args[1], str_repeat("\0", 1024));
exit(0);
