<?php

declare(strict_types=1);

if (3 !== $argc) {
    fwrite(STDERR, "Usage: php dump-test-support.php APP_HOME OUTPUT\n");
    exit(2);
}

$composerDirectory = rtrim($argv[1], '/').'/vendor/composer';
$metadata = [];

foreach (['psr4' => 'psr4', 'psr0' => 'namespaces', 'classmap' => 'classmap', 'files' => 'files'] as $key => $suffix) {
    $path = $composerDirectory.'/autoload_'.$suffix.'.php';
    $metadata[$key] = is_file($path) ? require $path : [];
}

$metadata['installed'] = require $composerDirectory.'/installed.php';

$output = "<?php\n\n// Generated from the qualification image's development autoload maps.\nreturn ".var_export($metadata, true).";\n";

if (false === file_put_contents($argv[2], $output)) {
    throw new RuntimeException('Cannot write the qualification autoload metadata.');
}
