<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Kernel;
use Defuse\Crypto\Key;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\NullHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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
                $container->setParameter('auth.encryption_key', Key::createNewRandomKey()->saveToAsciiSafeString());
                $container->setParameter('auth.oauth.issuer', 'https://baander.app');
                $container->setParameter('env(DATABASE_URL)', $this->databaseUrl);
            }
        });
    }
}
