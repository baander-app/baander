#!/usr/local/bin/php
<?php

declare(strict_types=1);

require '/var/www/html/tests/Fixtures/Worker/MediaControlObservation.php';

try {
    /** @var list<string> $argv */
    $arguments = array_slice($argv, 1);
    MediaControlObservation::recordLaunch($arguments);
    // exec retains the PID, start ticks, standard streams and native exit status.
    pcntl_exec('/usr/lib/baander-fixture/ffmpeg', $arguments);
    throw new RuntimeException('Unable to exec the fixture native encoder.');
} catch (Throwable $error) {
    fwrite(STDERR, 'Native observation failed: ' . $error->getMessage() . "\n");
    exit(125);
}
