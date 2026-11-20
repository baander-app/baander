<?php

namespace App;

use App\Shared\Domain\Event\Outbox\OutboxSubscriberPass;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Logging\BoundedContextLoggerPass;
use App\Shared\Infrastructure\Logging\MonologHandlerResetterPass;
use App\Shared\Infrastructure\Logging\TerminalLogGuardPass;
use App\Shared\Infrastructure\Security\ProductionSecrets;
use App\Shared\Infrastructure\Swoole\DBAL\DBALAliveKeeperCompilerPass;
use App\Shared\Infrastructure\Swoole\ServeCommandPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel\CoroutinesSupportingKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    public const int LOG_QUERY_HOOKS = SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_PROC;

    use CoroutinesSupportingKernel, MicroKernelTrait {
        CoroutinesSupportingKernel::initializeContainer insteadof MicroKernelTrait;
        CoroutinesSupportingKernel::getContainerBaseClass insteadof MicroKernelTrait;
    }

    /**
     * Registers custom DBAL types before any EntityManager is built.
     *
     * The Swoole bundle bypasses DoctrineBundle's ConnectionFactory when
     * proxifying the DBAL connection, so the `dbal.types` config block is
     * never applied. {@see CustomTypesRegistrar} restores them in a way that
     * covers both HTTP workers and console commands.
     *
     * A production process refuses to boot while a required secret is unset, a committed
     * default, a placeholder or too short ({@see ProductionSecrets}).
     */
    public function boot(): void
    {
        CustomTypesRegistrar::register();

        if (!$this->booted && $this->environment === 'prod') {
            new ProductionSecrets()->assertConfigured(ProductionSecrets::environmentValue(...));
        }

        parent::boot();
    }

    public function getLogDir(): string
    {
        $directory = ProductionSecrets::environmentValue('BAANDER_LOG_DIR');
        if ($directory === null || $directory === '') {
            return parent::getLogDir();
        }
        if (!str_starts_with($directory, '/') || str_contains($directory, "\0")
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $directory) === 1) {
            throw new \LogicException('BAANDER_LOG_DIR must be an absolute directory without traversal segments.');
        }

        return rtrim($directory, '/') ?: '/';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BoundedContextLoggerPass());
        $container->addCompilerPass(new DBALAliveKeeperCompilerPass());
        $container->addCompilerPass(new MonologHandlerResetterPass());
        $container->addCompilerPass(new TerminalLogGuardPass());
        $container->addCompilerPass(new OutboxSubscriberPass());
        $container->addCompilerPass(new \App\Shared\Infrastructure\Messaging\AsyncWorkloadCompilerPass(), PassConfig::TYPE_BEFORE_REMOVING, 10);
        $container->addCompilerPass(new ServeCommandPass(), PassConfig::TYPE_BEFORE_REMOVING, 10);
    }

}
