<?php
require '/var/www/html/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'prod';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
foreach (['APP_SECRET', 'STREAM_URL_HMAC_SECRET'] as $name) {
    $_ENV[$name] = $_SERVER[$name] = bin2hex(random_bytes(32));
}
$_ENV['AUTH_TOTP_ENCRYPTION_KEY'] = $_SERVER['AUTH_TOTP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/var/www/html/.env');
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'postgresql://probe:probe@baander.app:5432/probe';
$_ENV['REDIS_URL'] = $_SERVER['REDIS_URL'] = 'redis://baander.app:6379';
function snap(string $phase): void {
    echo json_encode(['phase' => $phase, 'used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'classes' => count(get_declared_classes()), 'vm' => swoole_get_vm_status(), 'gc' => gc_status()]), "\n";
}
snap('autoload');
$kernel = new App\Kernel('prod', false);
try {
    $kernel->boot();
    snap('kernel_boot');
    gc_disable();
    $store = ZEngine\Core::$executor->objectStore;
    $max = count($store);
    $histogram = [];
    for ($i = 1; $i <= $max; ++$i) {
        if (!isset($store[$i])) { continue; }
        try { $class = $store[$i]->getClass()->getName(); }
        catch (Throwable) { continue; }
        $histogram[$class] = ($histogram[$class] ?? 0) + 1;
    }
    arsort($histogram);
    echo json_encode(['histogram_before_gc' => array_slice($histogram, 0, 25, true)]), "\n";
    unset($histogram, $store);
    gc_enable();
    snap('before_gc');
    $collected = gc_collect_cycles();
    snap('after_cycle_collection');
    $freed = gc_mem_caches();
    snap('after_cache_collection');
    echo json_encode(['collected' => $collected, 'freed' => $freed]), "\n";
} catch (Throwable $error) {
    echo json_encode(['error' => $error::class, 'message' => $error->getMessage()]), "\n";
    exit(1);
}
