<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

final class ServicePoolContainer
{
    /** @var array<int, true> */
    private array $releasing = [];

    /**
     * @param array<int, array<ServicePool<object>>> $pools
     */
    public function __construct(private array $pools) {}

    /**
     * @param ServicePool<object> $pool
     */
    public function addPool(ServicePool $pool, int $priority = 0): void
    {
        $this->pools[$priority][] = $pool;
        krsort($this->pools);
    }

    public function releaseFromCoroutine(int $cId): void
    {
        if (isset($this->releasing[$cId])) {
            return;
        }

        $this->releasing[$cId] = true;
        try {
            $queue = $this->uniquePools();
            $registrationCount = $this->count();
            $queued = [];
            foreach ($queue as $pool) {
                $queued[spl_object_id($pool)] = true;
            }
            for ($index = 0; isset($queue[$index]); $index++) {
                $pool = $queue[$index];
                if ($pool instanceof LifecycleServicePool) {
                    try {
                        $pool->resetAssigned($cId);
                    } catch (\Throwable $error) {
                        $this->report('reset', $pool, $error);
                    }
                }

                if ($registrationCount === $this->count()) {
                    continue;
                }
                $registrationCount = $this->count();
                foreach ($this->getPools() as $registered) {
                    $id = spl_object_id($registered);
                    if (!isset($queued[$id])) {
                        $queued[$id] = true;
                        $queue[] = $registered;
                    }
                }
            }

            foreach ($this->uniquePools() as $pool) {
                try {
                    if ($pool instanceof LifecycleServicePool) {
                        $pool->releaseAssigned($cId);
                    } else {
                        $pool->releaseFromCoroutine($cId);
                    }
                } catch (\Throwable $error) {
                    $this->report('release', $pool, $error);
                }
            }
        } finally {
            unset($this->releasing[$cId]);
        }
    }

    public function drain(int $cId): void
    {
        $this->releaseFromCoroutine($cId);
        foreach ($this->uniquePools() as $pool) {
            if (!$pool instanceof LifecycleServicePool) {
                continue;
            }
            try {
                $pool->drainFree();
            } catch (\Throwable $error) {
                $this->report('drain', $pool, $error);
            }
        }
    }

    /** @return array<ServicePool<object>> */
    private function uniquePools(): array
    {
        $unique = [];
        foreach ($this->getPools() as $pool) {
            $unique[spl_object_id($pool)] = $pool;
        }

        return array_values($unique);
    }

    /** @param ServicePool<object> $pool */
    private function report(string $stage, ServicePool $pool, \Throwable $error): void
    {
        error_log(sprintf('Service pool %s failed for %s: %s: %s', $stage, $pool::class, $error::class, $error->getMessage()));
    }

    public function count(): int
    {
        return array_reduce(
            $this->pools,
            static fn(int $count, array $poolsWithPriority): int => $count + count($poolsWithPriority),
            0,
        );
    }

    /**
     * @return array<ServicePool<object>>
     */
    public function getPools(): array
    {
        $all = [];
        foreach ($this->pools as $poolsWithPriority) {
            foreach ($poolsWithPriority as $pool) {
                $all[] = $pool;
            }
        }

        return $all;
    }
}
