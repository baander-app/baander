<?php

namespace App;

use App\Shared\Domain\Event\Outbox\OutboxSubscriberPass;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Logging\BoundedContextLoggerPass;
use App\Shared\Infrastructure\Logging\MonologHandlerResetterPass;
use App\Shared\Infrastructure\RateLimiter\RateLimiterCatalogPass;
use App\Shared\Infrastructure\Swoole\DBAL\DBALAliveKeeperCompilerPass;
use App\Shared\Infrastructure\Swoole\ServeCommandPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel\CoroutinesSupportingKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
        $container->addCompilerPass(new \App\Shared\Infrastructure\Messaging\AsyncWorkloadCompilerPass(), PassConfig::TYPE_BEFORE_REMOVING, 10);
        $container->addCompilerPass(new ServeCommandPass(), PassConfig::TYPE_BEFORE_REMOVING, 10);
    }

}
