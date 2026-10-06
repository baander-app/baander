<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Security\OAuth\ResourceServerFactory;
use App\Kernel;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class OAuthProductionConfigurationTest extends TestCase
{
    private string $directory;
    /** @var list<OAuthProductionConfigurationKernel> */
    private array $kernels = [];
    /** @var array<string,array{server: mixed,serverExists: bool,env: mixed,envExists: bool,process: string|false}> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-oauth-prod-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsa);
        self::assertTrue(openssl_pkey_export_to_file($rsa, $this->directory . '/private.pem'));
        $details = openssl_pkey_get_details($rsa);
        self::assertIsArray($details);
        self::assertSame(strlen($details['key']), file_put_contents($this->directory . '/public.pem', $details['key']));
        self::assertTrue(chmod($this->directory . '/private.pem', 0600));
        self::assertTrue(chmod($this->directory . '/public.pem', 0600));
        $this->environment('OAUTH_PRIVATE_KEY_PATH', $this->directory . '/private.pem');
        $this->environment('OAUTH_PUBLIC_KEY_PATH', $this->directory . '/public.pem');
        // Kernel environment, rather than this ambient value, must govern admission.
        $this->environment('APP_ENV', 'test');
        $this->environment('DATABASE_URL', 'postgresql://worker:private@db.baander.app/unused?serverVersion=18&charset=utf8');
        $this->environment('REDIS_URL', 'redis://redis.baander.app:6379');
        $this->environment('APP_URL', 'https://baander.app');
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        foreach ($this->previousEnvironment as $name => $previous) {
            if ($previous['serverExists']) {
                $_SERVER[$name] = $previous['server'];
            } else {
                unset($_SERVER[$name]);
            }
            if ($previous['envExists']) {
                $_ENV[$name] = $previous['env'];
            } else {
                unset($_ENV[$name]);
            }
            putenv($previous['process'] === false ? $name : $name . '=' . $previous['process']);
        }
        (new Filesystem())->remove($this->directory);
    }

    public function testIndependentProductionContainersResolveExternalKeyPaths(): void
    {
        $first = $this->kernel();
        $second = $this->kernel();
        self::assertNotSame($first->getContainer(), $second->getContainer());
        foreach ([$first, $second] as $kernel) {
            $container = $kernel->getContainer();
            self::assertSame('prod', $container->getParameter('kernel.environment'));
            self::assertSame('test', $_SERVER['APP_ENV']);
            self::assertSame($this->directory . '/private.pem', $container->getParameter('auth.private_key_path'));
            self::assertSame($this->directory . '/public.pem', $container->getParameter('auth.public_key_path'));
            $resourceFactory = $container->get('oauth.production.resource_factory');
            self::assertInstanceOf(ResourceServerFactory::class, $resourceFactory);
            self::assertInstanceOf(ResourceServer::class, $resourceFactory->create());
        }
    }

    public function testAbsentPathEnvironmentPreservesProjectDefaultsWithoutOpeningRealKeys(): void
    {
        $this->environment('OAUTH_PRIVATE_KEY_PATH', null);
        $this->environment('OAUTH_PUBLIC_KEY_PATH', null);
        $container = $this->kernel()->getContainer();
        $root = dirname(__DIR__, 2);
        self::assertSame($root . '/config/secrets/oauth/private.key', $container->getParameter('auth.private_key_path'));
        self::assertSame($root . '/config/secrets/oauth/public.key', $container->getParameter('auth.public_key_path'));
    }

    private function kernel(): OAuthProductionConfigurationKernel
    {
        $kernel = new OAuthProductionConfigurationKernel($this->directory . '/kernel-' . count($this->kernels));
        $this->kernels[] = $kernel;
        $kernel->boot();
        return $kernel;
    }

    private function environment(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->previousEnvironment)) {
            $this->previousEnvironment[$name] = [
                'server' => $_SERVER[$name] ?? null, 'serverExists' => array_key_exists($name, $_SERVER),
                'env' => $_ENV[$name] ?? null, 'envExists' => array_key_exists($name, $_ENV), 'process' => getenv($name),
            ];
        }
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }
}

/** Actual application configuration and compiler passes, with isolated disk state. */
final class OAuthProductionConfigurationKernel extends Kernel
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
        $container->setAlias('oauth.production.resource_factory', ResourceServerFactory::class)->setPublic(true);
    }
}
