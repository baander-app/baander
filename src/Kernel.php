<?php

namespace App;

use App\Shared\Domain\Event\Outbox\OutboxSubscriberPass;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Logging\BoundedContextLoggerPass;
use App\Shared\Infrastructure\Logging\MonologHandlerResetterPass;
use App\Shared\Infrastructure\RateLimiter\RateLimiterCatalogPass;
use App\Shared\Infrastructure\Swoole\DBAL\DBALAliveKeeperCompilerPass;
use App\Shared\Infrastructure\Swoole\ServeCommandPass;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\BlockingContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Modifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel\CoroutinesSupportingKernel;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use CoroutinesSupportingKernel;
    use MicroKernelTrait;

    /**
     * Registers custom DBAL types before any EntityManager is built.
     *
     * The Swoole bundle bypasses DoctrineBundle's ConnectionFactory when
     * proxifying the DBAL connection, so the `dbal.types` config block is
     * never applied. {@see CustomTypesRegistrar} restores them in a way that
     * covers both HTTP workers and console commands.
     */
    public function boot(): void
    {
        CustomTypesRegistrar::register();

        parent::boot();
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BoundedContextLoggerPass());
        $container->addCompilerPass(new DBALAliveKeeperCompilerPass());
        $container->addCompilerPass(new MonologHandlerResetterPass());
        $container->addCompilerPass(new OutboxSubscriberPass());
        $container->addCompilerPass(new RateLimiterCatalogPass());
        $container->addCompilerPass(new ServeCommandPass(), PassConfig::TYPE_BEFORE_REMOVING, 10);
    }

    /**
     * Serializes container compilation across workers sharing the cache directory.
     */
    protected function initializeContainer(): void
    {
        $cacheDir = $this->getCacheDir();
        (new Filesystem())->mkdir($cacheDir);

        $lockFile = $cacheDir . '/.container.lock';
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the container initialization lock.');
        }

        try {
            if (!@flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire the container initialization lock.');
            }
            $this->bootContainer($cacheDir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
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

        if (!$this->container instanceof BlockingContainer) {
            throw new \LogicException('Coroutine support requires a blocking service container.');
        }

        Modifier::modifyContainer($this->container, $cacheDir, $this->isDebug());
        $this->container->set('kernel_original', $this);
        $this->container->set('kernel', $this->container->get('kernel_proxy'));
    }
}
