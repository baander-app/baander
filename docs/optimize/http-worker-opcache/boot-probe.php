<?php

declare(strict_types=1);

// Run twice in one disposable container to compare cold and warm container caches.
$root = getcwd();
require $root . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
$environment = getenv('PROBE_ENV') ?: 'dev';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = $environment === 'dev' ? '1' : '0';
foreach (['APP_SECRET', 'STREAM_URL_HMAC_SECRET'] as $key) {
    $_ENV[$key] = $_SERVER[$key] = bin2hex(random_bytes(32));
}
$_ENV['AUTH_TOTP_ENCRYPTION_KEY'] = $_SERVER['AUTH_TOTP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'postgresql://probe:probe@baander.app:5432/probe';
$_ENV['REDIS_URL'] = $_SERVER['REDIS_URL'] = 'redis://baander.app:6379';

$kernel = new class($environment, $environment === 'dev') extends App\Kernel {
    public function getProjectDir(): string
    {
        return getcwd();
    }

    public function getCacheDir(): string
    {
        return '/tmp/memory-probe/cache';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return '/tmp/memory-probe/log';
    }
};
$kernel->boot();
$collected = gc_collect_cycles();
gc_mem_caches();
preg_match_all('/^(Rss|Pss):\s+(\d+)/m', file_get_contents('/proc/self/smaps_rollup'), $matches, PREG_SET_ORDER);
$physical = [];
foreach ($matches as $match) {
    $physical[$match[1]] = (int) $match[2];
}
echo json_encode([
    'environment' => $environment,
    'usedBytes' => memory_get_usage(),
    'allocatedBytes' => memory_get_usage(true),
    'classes' => count(get_declared_classes()),
    'collectedCycles' => $collected,
    'physicalKiB' => $physical,
    'opcache' => opcache_get_status(false)['memory_usage'] ?? null,
], JSON_THROW_ON_ERROR) . "\n";
