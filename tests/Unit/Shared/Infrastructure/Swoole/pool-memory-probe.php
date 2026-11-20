<?php
declare(strict_types=1);
require dirname(__DIR__, 5) . '/vendor/autoload.php';
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
$adapter = new Adapter();
$mutex = new class implements Mutex {
    public function acquire(): void {}
    public function release(): void {}
    public function isAcquired(): bool { return false; }
};
/** @var UnmanagedFactoryServicePool<object> $pool */
$pool = new UnmanagedFactoryServicePool(static fn() => (object) ['payload' => str_repeat('x', 1024 * 1024)], $adapter, $mutex, 64);
$container = new ServicePoolContainer([0 => [$pool]]);
$results = [];
$sample = static function (string $phase) use ($pool, &$results): void {
    $memory = ['php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true), 'free' => $pool->getFreeCount(), 'assigned' => $pool->getAssignedCount()];
    $rollup = file_get_contents('/proc/self/smaps_rollup');
    foreach (['Rss', 'Pss', 'Private_Clean', 'Private_Dirty'] as $key) {
        preg_match('/^' . $key . ':\s+(\d+)/m', $rollup, $match);
        $memory[$key . '_KiB'] = (int) ($match[1] ?? 0);
    }
    $results[$phase] = $memory;
};
$sample('boot');
Swoole\Coroutine\run(static function () use ($pool, $container, $adapter, $sample): void {
    $ready = new Swoole\Coroutine\Channel(32);
    $release = new Swoole\Coroutine\Channel(32);
    $done = new Swoole\Coroutine\Channel(32);
    for ($i = 0; $i < 32; $i++) {
        Swoole\Coroutine::create(static function () use ($pool, $adapter, $ready, $release, $done): void {
            $pool->get();
            $ready->push(true);
            $release->pop();
            $pool->releaseFromCoroutine($adapter->getCoroutineId());
            $done->push(true);
        });
    }
    for ($i = 0; $i < 32; $i++) { $ready->pop(); }
    $burstAssignments = $pool->getAssignedCount();
    if ($burstAssignments !== 32) {
        throw new RuntimeException('The workload did not allocate 32 concurrent instances');
    }
    $sample('burst');
    for ($i = 0; $i < 32; $i++) { $release->push(true); }
    for ($i = 0; $i < 32; $i++) { $done->pop(); }
    $sample('released');
    gc_collect_cycles();
    gc_mem_caches();
    $sample('gc_only');
    if (($GLOBALS['argv'][1] ?? 'baseline') === 'reclaim') {
        $removed = $container->pruneFree(0.0);
        if ($removed !== 31 || $pool->getFreeCount() !== 1) {
            throw new RuntimeException('Idle reclamation did not retain exactly one warm instance');
        }
        $sample('pruned');
        gc_collect_cycles();
        gc_mem_caches();
        $sample('reclaimed');
        $pool->get();
        if ($pool->getAssignedCount() !== 1) {
            throw new RuntimeException('Borrowing after reclamation failed');
        }
        $pool->releaseFromCoroutine($adapter->getCoroutineId());
        $sample('reborrowed');
    }
});
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
