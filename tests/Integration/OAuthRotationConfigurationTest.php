<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Security\OAuth\DoctrineOAuthTokenInvalidator;
use App\Auth\Interface\Console\RotateSecretsCommand;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class OAuthRotationConfigurationTest extends TestCase
{
    public function testRealContainerUsesTokenPoolAndPreparesPrivateBundlesWithoutCacheInvalidation(): void
    {
        $directory = sys_get_temp_dir() . '/baander-oauth-rotation-config-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $kernel = new OAuthRotationConfigurationKernel($directory . '/kernel');
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $tokenCache = $this->createMock(TagAwareCacheInterface::class);
            $tokenCache->expects(self::never())->method('invalidateTags');
            $defaultCache = $this->createStub(TagAwareCacheInterface::class);
            $container->set('cache.tags', $tokenCache);
            $container->set('cache.app.taggable', $defaultCache);
            $invalidator = $container->get('oauth.rotation.invalidator');
            self::assertInstanceOf(DoctrineOAuthTokenInvalidator::class, $invalidator);
            $injected = new ReflectionProperty(DoctrineOAuthTokenInvalidator::class, 'cache')->getValue($invalidator);
            self::assertSame($container->get('cache.tags'), $injected, 'Token invalidation must use the repository token pool.');
            self::assertNotSame($defaultCache, $injected);

            $command = $container->get('oauth.rotation.command');
            self::assertInstanceOf(RotateSecretsCommand::class, $command);
            $tester = new CommandTester($command);
            $bundle = $directory . '/bundle';
            self::assertSame(Command::SUCCESS, $tester->execute([
                'action' => 'prepare', '--directory' => $bundle,
            ], ['interactive' => false]));
            self::assertFileExists($bundle . '/manifest.json');
            self::assertSame(0700, fileperms($bundle) & 07777);
            $configuration = file_get_contents($bundle . '/oauth.env');
            self::assertIsString($configuration);
            self::assertMatchesRegularExpression('/\AOAUTH_PRIVATE_KEY_PATH=.*\nOAUTH_PUBLIC_KEY_PATH=.*\nOAUTH_ENCRYPTION_KEY=([^\n]+)\n\z/D', $configuration);
            $secret = file_get_contents($bundle . '/encryption.key');
            self::assertIsString($secret);
            self::assertStringNotContainsString(trim($secret), $tester->getDisplay());
            self::assertStringContainsString($bundle . '/oauth.env', $tester->getDisplay());

            self::assertSame(Command::SUCCESS, $tester->execute([
                'action' => 'validate', '--directory' => $bundle,
            ], ['interactive' => false]));
            self::assertStringNotContainsString(trim($secret), $tester->getDisplay());
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
        }
    }
}

/** Keep actual application wiring and replace only external cache transports. */
final class OAuthRotationConfigurationKernel extends Kernel
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.rotation.invalidator', DoctrineOAuthTokenInvalidator::class)->setPublic(true);
        $container->setAlias('oauth.rotation.command', RotateSecretsCommand::class)->setPublic(true);
        $container->addCompilerPass(new OAuthRotationCacheTransportPass());
    }
}

final class OAuthRotationCacheTransportPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (['cache.tags', 'cache.app.taggable'] as $id) {
            if ($container->hasAlias($id)) {
                $container->removeAlias($id);
            }
            $container->setDefinition($id, new Definition(TagAwareCacheInterface::class)->setSynthetic(true)->setPublic(true));
        }
    }
}
