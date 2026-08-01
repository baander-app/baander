<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container;

use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

final class CoWrapper
{
    public function __construct(
        private readonly ServicePoolContainer $servicePoolContainer,
        private readonly Swoole $swoole,
    ) {}

    public function defer(): void
    {
        Coroutine::defer(function (): void {
            $this->servicePoolContainer->releaseFromCoroutine($this->swoole->getCoroutineId());
        });
    }

    /**
     * Instead of Co::go(), CoWrapper::go() has to be used to run coroutines in Symfony apps, so Symfony
     * is able to reset all stateful service instances.
     *
     * @SuppressWarnings(PHPMD.ShortMethodName)
     */
    public function go(callable $fn): void
    {
        Coroutine::create(function () use ($fn): void {
            $this->defer();
            $fn();
        });
    }
}
