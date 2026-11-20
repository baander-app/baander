<?php

declare(strict_types=1);

global $argv;

echo "waiting\n";
fflush(STDOUT);
fgets(STDIN);
$launcher = $argv[1];
$argv = [$launcher, 'wait'];
$argc = count($argv);
require $launcher;
