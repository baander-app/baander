<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ApplicationContainerBootstrapTest extends TestCase
{
    public function testApplicationKernelReplaysNativeMetadataOnWarmBootstrap(): void
    {
        $root = dirname(__DIR__, 5);
        $cache = sys_get_temp_dir() . '/baander-kernel-bootstrap-' . bin2hex(random_bytes(8));
        $script = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($argv[1] . '/.env');
class ApplicationBootstrapKernel extends App\Kernel
{
    public function getProjectDir(): string { return $GLOBALS['argv'][1]; }
    public function getCacheDir(): string { return $GLOBALS['argv'][2]; }
    public function getBuildDir(): string { return $this->getCacheDir(); }
    public function getLogDir(): string { return $this->getCacheDir() . '/log'; }
}
$kernel = new ApplicationBootstrapKernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();
$manager = $container->get('doctrine.orm.entity_manager');
$connection = $manager->getConnection();
if (!$connection instanceof Doctrine\DBAL\Connection || (new ReflectionMethod(Doctrine\DBAL\Connection::class, 'convertExceptionDuringQuery'))->isFinal()) {
    throw new RuntimeException('The warm application container did not restore native proxy metadata.');
}
echo "application connection proxy ready\n";
$kernel->shutdown();
PHP;
        try {
            new Filesystem()->mkdir($cache);
            file_put_contents($cache . '/kernel-probe.php', $script);
            foreach (['cold', 'warm'] as $boot) {
                $process = new Process([PHP_BINARY, $cache . '/kernel-probe.php', $root, $cache]);
                $process->setTimeout(90);
                $process->run();
                self::assertTrue($process->isSuccessful(), $boot . ': ' . $process->getOutput() . $process->getErrorOutput());
                self::assertStringContainsString("application connection proxy ready\n", $process->getOutput());
            }
        } finally {
            new Filesystem()->remove($cache);
        }
    }
}
