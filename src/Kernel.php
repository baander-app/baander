<?php

namespace App;

use App\Shared\Domain\Event\Outbox\OutboxSubscriberPass;
use App\Shared\Infrastructure\Logging\BoundedContextLoggerPass;
use App\Shared\Infrastructure\Logging\MonologHandlerResetterPass;
use App\Shared\Infrastructure\Swoole\DBAL\DBALAliveKeeperCompilerPass;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\BlockingContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Modifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel\CoroutinesSupportingKernel;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use CoroutinesSupportingKernel;
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BoundedContextLoggerPass());
        $container->addCompilerPass(new DBALAliveKeeperCompilerPass());
        $container->addCompilerPass(new MonologHandlerResetterPass());
        $container->addCompilerPass(new OutboxSubscriberPass());
    }

    /**
     * Wraps container initialization in an exclusive file lock and temporarily
     * disables coroutine hooks to prevent segfaults from hooked flock() and
     * race conditions when multiple Swoole workers compile the cache simultaneously.
     */
    protected function initializeContainer(): void
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $lockFile = $cacheDir . '/.container.lock';
        $lock = fopen($lockFile, 'c+');

        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }

        try {
            $this->bootContainer($cacheDir);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Replicates CoroutinesSupportingKernel::initializeContainer() logic,
     * which we must inline because our override hides the trait method.
     */
    private function bootContainer(string $cacheDir): void
    {
        ClassModifier::initialize($cacheDir);

        // Re-initialize the mutex on every boot — the Swoole Coroutine\Channel
        // used internally does not survive forks or HMR worker restarts.
        BlockingContainer::initializeMutex(new Swoole());

        parent::initializeContainer();

        if (!$this->areCoroutinesEnabled()) {
            return;
        }

        Modifier::modifyContainer($this->container, $cacheDir, $this->isDebug());
        $this->container->set('kernel_original', $this);
        $this->container->set('kernel', $this->container->get('kernel_proxy'));
    }
}
