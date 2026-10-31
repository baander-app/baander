<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Router;

use Override;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;

final class LockingConfigCacheFactory implements ConfigCacheFactoryInterface
{
    /**
     * @var array<string, true>
     */
    private array $initializationCompleted = [];

    public function __construct(
        private ConfigCacheFactoryInterface $decorated,
        private Mutex $mutex,
    ) {}

    #[Override]
    public function cache(string $file, callable $callable): ConfigCacheInterface
    {
        if (isset($this->initializationCompleted[$file])) {
            return $this->decorated->cache($file, $callable);
        }

        $this->mutex->acquire();

        try {
            if (isset($this->initializationCompleted[$file])) {
                return $this->decorated->cache($file, $callable);
            }

            $cache = $this->decorated->cache($file, $callable);
            $this->initializationCompleted[$file] = true;

            return $cache;
        } finally {
            $this->mutex->release();
        }
    }
}
