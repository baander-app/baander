<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Security\OAuth\DoctrineOAuthTokenInvalidator;
use App\Auth\Interface\Console\RotateSecretsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Production OAuth services with isolated signing bundles and one cache namespace across restarts. */
final class OAuthKeyRotationKernel extends ProductionOAuthKernel
{
    public function __construct(
        private readonly string $runtimeDirectory,
        string $databaseUrl,
        private readonly string $bundleDirectory,
        private readonly string $cacheSeed,
    ) {
        parent::__construct($runtimeDirectory, $databaseUrl);
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . hash('sha256', $this->runtimeDirectory);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setParameter('cache.prefix.seed', $this->cacheSeed);
        $container->setAlias('oauth.rotation.command', RotateSecretsCommand::class)->setPublic(true);
        $container->setAlias('oauth.rotation.invalidator', DoctrineOAuthTokenInvalidator::class)->setPublic(true);
        $container->addCompilerPass(new class($this->bundleDirectory) implements CompilerPassInterface {
            public function __construct(private readonly string $bundleDirectory)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('auth.private_key_path', $this->bundleDirectory . '/private.key');
                $container->setParameter('auth.public_key_path', $this->bundleDirectory . '/public.key');
            }
        });
    }
}
