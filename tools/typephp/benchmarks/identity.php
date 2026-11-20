<?php

declare(strict_types=1);

$expected = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$mismatches = [];
foreach ($expected as $path => $hash) {
    $bytes = file_get_contents('/var/www/html/' . $path);
    if ($bytes === false || hash('sha256', $bytes) !== $hash) {
        $mismatches[] = $path;
    }
}
if ($mismatches !== []) {
    fwrite(STDERR, json_encode($mismatches, JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
echo count($expected) . " source files match\n";
