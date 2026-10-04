<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ResourceBoundaryTest extends TestCase
{
    public function testResourcesCanMapModelsWhileControllersCannotDependOnThem(): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander-boundary-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->mkdir($directory);

        try {
            $files->dumpFile($directory . '/Fixture.php', <<<'SOURCE'
<?php
namespace App\Catalog\Domain\Model;
final class BoundaryModel {}
namespace App\Catalog\Interface\Resource;
final class BoundaryResource {
    public function map(\App\Catalog\Domain\Model\BoundaryModel $model): string { return 'mapped'; }
}
namespace App\Catalog\Interface\Controller;
final class BoundaryController {
    public function handle(\App\Catalog\Domain\Model\BoundaryModel $model): string { return 'forbidden'; }
}
SOURCE);
            $config = Yaml::parseFile($root . '/deptrac.yaml');
            unset($config['imports']);
            $config['deptrac']['paths'] = [$directory];
            $files->dumpFile($directory . '/deptrac.yaml', Yaml::dump($config, 12));
            $process = new Process([
                PHP_BINARY,
                $root . '/vendor/bin/deptrac',
                'analyse',
                '--config-file=' . $directory . '/deptrac.yaml',
                '--no-cache',
                '--no-progress',
                '--formatter=json',
            ], $root);
            $process->setTimeout(30);
            $process->run();
            self::assertJson($process->getOutput(), $process->getErrorOutput() . $process->getOutput());
            $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame(0, $report['Report']['Errors']);
            self::assertGreaterThan(0, $report['Report']['Allowed']);
            self::assertGreaterThan(0, $report['Report']['Violations']);
            self::assertSame(0, $report['Report']['Uncovered']);
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    self::assertStringContainsString('BoundaryController must not depend on', $message['message']);
                    self::assertStringNotContainsString('BoundaryResource', $message['message']);
                }
            }
        } finally {
            $files->remove($directory);
        }
    }
}
