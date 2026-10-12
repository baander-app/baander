<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Kernel;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\NullHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The production kernel over a disposable PostgreSQL and Redis, for the OAuth acceptance
 * tests. The issuance fault injectors (IssuanceFaults) are registered here and stay inert
 * unless a process names a flag directory; the pool statistics are exposed for the
 * pool-growth proof.
 */
class ProductionOAuthKernel extends Kernel
{
    public function __construct(private readonly string $directory, private readonly string $databaseUrl)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/logs';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.entity_manager', EntityManagerInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.users', UserRepositoryInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.pool_stats', SwoolePoolStatsProvider::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.redis_pool', RedisClientFactory::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.service_pools', ServicePoolContainer::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.swoole', Swoole::class)->setPublic(true);
        // A definition set here exists when the Doctrine middleware pass runs at compile time.
        $container->register(CommitFaultMiddleware::class)->addTag('doctrine.middleware');
        $container->register(FaultInjectingJwtGenerator::class)
            ->setDecoratedService(JwtGeneratorInterface::class)
            ->setArguments([new Reference(FaultInjectingJwtGenerator::class . '.inner')]);
        $container->addCompilerPass(new class($this->directory, $this->databaseUrl) implements CompilerPassInterface {
            public function __construct(private readonly string $directory, private readonly string $databaseUrl)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                foreach (['stdout', 'security', 'messenger'] as $handler) {
                    $container->setDefinition('monolog.handler.' . $handler, new Definition(NullHandler::class));
                }
                $container->setParameter('auth.private_key_path', $this->directory . '/private.pem');
                $container->setParameter('auth.public_key_path', $this->directory . '/public.pem');
                $container->setParameter('auth.oauth.issuer', 'https://baander.app');
                $container->setParameter('env(DATABASE_URL)', $this->databaseUrl);
                // No collector is reachable from the test containers; a long request would
                // otherwise print an export failure and make the test risky. The prod
                // environment reads OTEL_ENABLED from the real environment, so the
                // application parameter is set directly, as when@test does.
                $container->setParameter('app.otel.enabled', false);
            }
        });
    }
}
