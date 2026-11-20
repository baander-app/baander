<?php
declare(strict_types=1);

$status = opcache_get_status(false);
if (!($status['opcache_enabled'] ?? false) || ini_get('opcache.enable_cli') !== '1') {
    throw new RuntimeException('CLI OPcache is disabled.');
}
$probe = tempnam(sys_get_temp_dir(), 'baander-opcache-');
try {
    file_put_contents($probe, '<?php return 41;');
    touch($probe, time() - 10);
    if (!opcache_compile_file($probe) || !opcache_is_script_cached($probe) || (include $probe) !== 41) {
        throw new RuntimeException('The initial script was not cached correctly.');
    }
    file_put_contents($probe, '<?php return 42;');
    touch($probe, time() - 5);
    clearstatcache(true, $probe);
    if ((include $probe) !== 42) {
        throw new RuntimeException('The changed source was not revalidated.');
    }
    echo json_encode([
        'cachedScript' => true,
        'changedSourceObserved' => true,
        'opcacheCapacityMiB' => ini_get('opcache.memory_consumption'),
        'internedStringsMiB' => ini_get('opcache.interned_strings_buffer'),
        'timestampValidation' => ini_get('opcache.validate_timestamps'),
        'revalidateFrequency' => ini_get('opcache.revalidate_freq'),
        'jit' => ini_get('opcache.jit'),
        'memoryLimit' => ini_get('memory_limit'),
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    unlink($probe);
}
