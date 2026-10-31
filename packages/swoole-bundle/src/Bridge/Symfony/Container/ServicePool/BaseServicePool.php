<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Initializer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\StabilityChecker;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;

/**
 * @template T of object
 * @template-implements LifecycleServicePool<T>
 */
abstract class BaseServicePool implements LifecycleServicePool
{
    private int $assignedCount = 0;

    /**
     * @var array<int, T>
     */
    private array $freePool = [];

    /**
     * @var array<int, T>
     */
    private array $assignedPool = [];

    /** @var array<int, array{service: T, reusable: bool}> */
    private array $prepared = [];

    /** @var array<int, true> */
    private array $resetting = [];

    public function getAssignedCount(): int
    {
        return $this->assignedCount;
    }

    public function getFreeCount(): int
    {
        return count($this->freePool);
    }

    public function getInstancesLimit(): int
    {
        return $this->instancesLimit;
    }

    public function __construct(
        private readonly Swoole $swoole,
        private readonly Mutex $mutex,
        private readonly int $instancesLimit = 50,
        private readonly ?Resetter $resetter = null,
        private readonly ?StabilityChecker $stabilityChecker = null,
        private readonly ?Initializer $initializer = null,
    ) {}

    /**
     * @return T
     */
    public function get(): object
    {
        $cId = $this->getCoroutineId();

        if (isset($this->assignedPool[$cId])) {
            return $this->assignedPool[$cId];
        }

        if ($this->assignedCount >= $this->instancesLimit) {
            // this will wait until a different coroutine will release the lock
            $this->lockPool();
        }

        $this->assignedCount++;

        return $this->assignedPool[$cId] = $this->getServiceToAssign();
    }

    public function releaseFromCoroutine(int $cId): void
    {
        try {
            $this->resetAssigned($cId);
        } finally {
            $this->releaseAssigned($cId);
        }
    }

    public function resetAssigned(int $cId): void
    {
        $service = $this->assignedPool[$cId] ?? null;
        if ($service === null || ($this->prepared[$cId]['service'] ?? null) === $service) {
            return;
        }

        $this->prepared[$cId] = ['service' => $service, 'reusable' => false];
        $this->resetting[$cId] = true;
        try {
            $this->resetter?->reset($service);
            $this->prepared[$cId]['reusable'] = true;
        } catch (\Throwable $error) {
            error_log(sprintf('Service pool reset failed for %s: %s: %s', $service::class, $error::class, $error->getMessage()));
        } finally {
            unset($this->resetting[$cId]);
        }
    }

    public function releaseAssigned(int $cId): void
    {
        if (isset($this->resetting[$cId])) {
            return;
        }

        $service = $this->assignedPool[$cId] ?? null;
        $prepared = $this->prepared[$cId] ?? null;
        unset($this->prepared[$cId]);
        if ($service === null) {
            return;
        }

        unset($this->assignedPool[$cId]);
        $this->assignedCount--;
        try {
            if ($prepared !== null && $prepared['service'] === $service && $prepared['reusable'] && $this->isServiceStable($service)) {
                $this->freePool[] = $service;
            }
        } catch (\Throwable $error) {
            error_log(sprintf('Service pool release failed for %s: %s: %s', $service::class, $error::class, $error->getMessage()));
        } finally {
            $this->unlockPool();
        }
    }

    public function drainFree(): void
    {
        $this->freePool = [];
    }

    /**
     * @return T
     */
    abstract protected function newServiceInstance(): object;

    /**
     * @return T
     */
    private function getServiceToAssign(): object
    {
        if (!empty($this->freePool)) {
            $assigned = array_shift($this->freePool);
        } else {
            $assigned = $this->newServiceInstance();
        }

        $this->initializer?->initialize($assigned);

        return $assigned;
    }

    private function getCoroutineId(): int
    {
        return $this->swoole->getCoroutineId();
    }

    private function isServiceStable(object $service): bool
    {
        return $this->stabilityChecker === null || $this->stabilityChecker->isStable($service);
    }

    private function lockPool(): void
    {
        $this->mutex->acquire();
    }

    private function unlockPool(): void
    {
        if (!$this->mutex->isAcquired()) {
            return;
        }

        $this->mutex->release();
    }
}
