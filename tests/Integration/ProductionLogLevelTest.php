<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class ProductionLogLevelTest extends TestCase
{
    public function testTheCompiledProductionConfigurationLogsBothCopiesAtInfo(): void
    {
        $directory = sys_get_temp_dir() . '/baander-log-level-' . bin2hex(random_bytes(8));
        $kernel = new ProductionLogLevelKernel($directory);
        try {
            $kernel->boot();
            $levels = [];
            foreach (ProductionLogLevelKernel::HANDLERS as $id) {
                $handler = $kernel->getContainer()->get($id);
                self::assertInstanceOf(StreamHandler::class, $handler);
                $levels[(string) $handler->getUrl()] = $handler->getLevel();
            }

            self::assertSame([
                $directory . '/log/prod.log' => Level::Info,
                $directory . '/log/error.log' => Level::Warning,
                'php://stderr' => Level::Info,
            ], $levels);
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($directory);
        }
    }
}

final class ProductionLogLevelKernel extends Kernel
{
    public const array HANDLERS = ['monolog.handler.file', 'monolog.handler.error', 'monolog.handler.stdout'];

    public function __construct(private readonly string $directory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->directory . '/cache'; }
    public function getLogDir(): string { return $this->directory . '/log'; }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (ProductionLogLevelKernel::HANDLERS as $id) {
                    $container->findDefinition($id)->setPublic(true);
                }
            }
        });
    }
}
