<?php

declare(strict_types=1);

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

if (($argv[1] ?? '') === '--runtime-info') {
    $settings = [];
    foreach (explode(',', $argv[2]) as $name) {
        $settings[$name] = ini_get($name);
    }
    $extensions = get_loaded_extensions();
    sort($extensions);
    $extensionVersions = [];
    foreach ($extensions as $extension) {
        $extensionVersions[$extension] = phpversion($extension);
    }
    echo json_encode([
        'version' => PHP_VERSION,
        'zts' => PHP_ZTS,
        'debug' => PHP_DEBUG,
        'binary' => PHP_BINARY,
        'binary_sha256' => hash_file('sha256', PHP_BINARY),
        'ini' => $settings,
        'extensions' => $extensions,
        'extension_versions' => $extensionVersions,
        'loaded_ini' => php_ini_loaded_file(),
        'scanned_ini' => php_ini_scanned_files(),
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

require '/var/www/html/vendor/autoload.php';

$iterations = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($iterations === false) {
    fwrite(STDERR, "Expected a positive iteration count.\n");
    exit(2);
}

$started = hrtime(true);
$checksum = hash_init('sha256');
for ($iteration = 0; $iteration < $iterations; ++$iteration) {
    $publicId = PublicId::fromString('0123456789abcdefghijk');
    $uuid = Uuid::fromString('01890f47-14c4-7cc0-98c4-dc0c1c073ef6');
    $encoded = json_encode([
        'public_id' => $publicId,
        'uuid' => $uuid,
        'binary' => bin2hex($uuid->toBinary()),
        'equal' => $publicId->equals(new PublicId($publicId->toString()))
            && $uuid->equals(new Uuid($uuid->toString())),
    ], JSON_THROW_ON_ERROR);
    hash_update($checksum, $encoded);
}
$elapsed = hrtime(true) - $started;
echo json_encode([
    'iterations' => $iterations,
    'checksum' => hash_final($checksum),
    'elapsed_ns' => $elapsed,
], JSON_THROW_ON_ERROR) . "\n";
