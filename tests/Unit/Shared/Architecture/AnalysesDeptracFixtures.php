<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the real deptrac.yaml rules (without the baseline) against fixture source.
 */
trait AnalysesDeptracFixtures
{
    /**
     * @return list<string> Unique "X must not depend on Y" violations, in report order.
     */
    private static function deptracViolations(string $source): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander-deptrac-fixture-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->mkdir($directory);

        try {
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
            self::assertSame(0, $report['Report']['Errors']);

            $violations = [];
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    $violations[] = explode(' (', $message['message'], 2)[0];
                }
            }

            return array_values(array_unique($violations));
        } finally {
            $files->remove($directory);
        }
    }
}
