<?php

declare(strict_types=1);

// The drain watchdog command without the kernel, for the unit tests that start a watchdog:
// php drain-watchdog-console.php <vendor/autoload.php> app:server:drain:watch --deadline-ns=...

use App\Shared\Infrastructure\Swoole\Drain\DrainWatchdogCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
require $arguments[1];

$application = new Application();
$application->addCommand(new DrainWatchdogCommand());
exit($application->run(new ArgvInput(array_slice($arguments, 1))));
