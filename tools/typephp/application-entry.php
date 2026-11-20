<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
$script = match ($arguments[1] ?? null) {
    'console' => dirname(__DIR__, 2).'/bin/console',
    'operator' => dirname(__DIR__, 2).'/bin/worker-deployment.php',
    default => null,
};

if ($script === null) {
    fwrite(STDERR, "Usage: baander console [command] [arguments...]\n       baander operator <action> [arguments...]\n");
    exit(2);
}

$arguments = [$script, ...array_slice($arguments, 2)];
$_SERVER['argv'] = $arguments;
$_SERVER['argc'] = count($arguments);
$GLOBALS['argv'] = $arguments;
$GLOBALS['argc'] = count($arguments);
$_SERVER['SCRIPT_FILENAME'] = $script;
$_SERVER['PHP_SELF'] = $script;

return require $script;
