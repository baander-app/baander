<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ContextCoverageTest extends TestCase
{
    public function testPreviouslyUncoveredContextsRejectInfrastructureLeaks(): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander-boundary-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->mkdir($directory);

        try {
            $source = '<?php' . PHP_EOL;
            foreach (['Radio', 'Scheduler', 'Session', 'QoL', 'Lyrics', 'Filesystem'] as $context) {
                $source .= "namespace App\\{$context}\\Infrastructure; final class BoundaryAdapter {}\n";
                $source .= "namespace App\\{$context}\\Application; final class BoundaryUseCase {\n";
                $source .= "public function execute(\\App\\{$context}\\Infrastructure\\BoundaryAdapter \$adapter): void {}\n}\n";
            }
            $files->dumpFile($directory . '/Fixture.php', $source);
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
            self::assertGreaterThan(0, $report['Report']['Violations']);
            self::assertSame(0, $report['Report']['Uncovered']);
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    self::assertStringContainsString('BoundaryUseCase must not depend on', $message['message']);
                }
            }
        } finally {
            $files->remove($directory);
        }
    }
}
