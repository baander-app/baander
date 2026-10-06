<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class SchedulerContractBoundaryTest extends TestCase
{
    public function testOtherContextsMayImplementSchedulerContractsButNotUseSchedulerInternals(): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander-scheduler-boundary-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->mkdir($directory);

        try {
            $files->dumpFile($directory . '/Fixture.php', <<<'SOURCE'
<?php
namespace App\Scheduler\Domain\Model;
interface SchedulableCommandInterface {}
interface SchedulableConsoleCommandInterface {}
trait SchedulerParameterSchema {}
final class InternalSchedulerModel {}
namespace App\Scheduler\Domain\Service;
final class BoundaryRegistry {
    public function register(\App\Scheduler\Domain\Model\SchedulableCommandInterface $command): void {}
}
namespace App\Catalog\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {
    public function internal(\App\Scheduler\Domain\Model\InternalSchedulerModel $model): void {}
}
namespace App\Lyrics\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {
    use \App\Scheduler\Domain\Model\SchedulerParameterSchema;
}
namespace App\Media\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {}
namespace App\Transcode\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {}
namespace App\Transcode\Interface\Console;
final class BoundaryScheduledConsoleCommand implements \App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface {}
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

            self::assertSame(0, $report['Report']['Errors']);
            $violations = [];
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    $violations[] = $message['message'];
                }
            }

            self::assertSame(
                ['App\Catalog\Application\Command\BoundaryScheduledCommand must not depend on App\Scheduler\Domain\Model\InternalSchedulerModel'],
                array_values(array_unique(array_map(
                    static fn (string $message): string => explode(' (', $message, 2)[0],
                    $violations,
                ))),
            );
        } finally {
            $files->remove($directory);
        }
    }
}
