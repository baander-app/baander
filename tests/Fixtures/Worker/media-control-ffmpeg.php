#!/usr/local/bin/php
<?php

declare(strict_types=1);

require '/var/www/html/tests/Fixtures/Worker/MediaControlObservation.php';

try {
    /** @var list<string> $argv */
    $arguments = array_slice($argv, 1);
    MediaControlObservation::recordLaunch($arguments);
    // Cost measurement reads encoder progress without changing the encode itself.
    $progress = '/var/www/html/storage/two-command/cost-progress';
    if (is_dir($progress)) {
        $arguments = ['-progress', $progress . '/' . getmypid() . '.txt', '-stats_period', '0.5', ...$arguments];
    }
    // exec retains the PID, start ticks, standard streams and native exit status.
    pcntl_exec('/usr/lib/baander-fixture/ffmpeg', $arguments);
    throw new RuntimeException('Unable to exec the fixture native encoder.');
} catch (Throwable $error) {
    fwrite(STDERR, 'Native observation failed: ' . $error->getMessage() . "\n");
    exit(125);
}
