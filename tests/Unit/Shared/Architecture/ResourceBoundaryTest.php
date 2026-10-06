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
    public function scope(\App\Library\Application\Port\LibraryReadScopeProviderInterface $scope): void {}
    public function internal(\App\Library\Application\InternalApplicationService $service): void {}
}
namespace App\Library\Application\Port;
interface LibraryReadScopeProviderInterface {}
namespace App\Playlist\Interface\Controller;
final class BoundaryController {
    public function scope(\App\Library\Application\Port\LibraryReadScopeProviderInterface $scope): void {}
    public function internal(\App\Library\Application\InternalApplicationService $service): void {}
}
namespace App\Lyrics\Interface\Controller;
final class BoundaryController {
    public function scope(\App\Library\Application\Port\LibraryReadScopeProviderInterface $scope): void {}
    public function internal(\App\Library\Application\InternalApplicationService $service): void {}
}
namespace App\Library\Application;
final class InternalApplicationService {}
namespace App\Auth\Application\Port;
interface AuthenticatedUserIdentityInterface {}
namespace App\Auth\Application;
final class InternalApplicationService {}
namespace App\Notification\Interface\Controller;
final class BoundaryController {
    public function user(\App\Auth\Application\Port\AuthenticatedUserIdentityInterface $identity): void {}
    public function internal(\App\Auth\Application\InternalApplicationService $service): void {}
}

namespace App\Media\Infrastructure;
final class BoundaryReadScopeProvider {
    public function current(
        \App\Library\Application\Port\LibraryReadScopeProviderInterface $scope,
        \App\Auth\Application\Port\AuthenticatedUserIdentityInterface $identity,
    ): void {}
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
            $violations = [];
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    $violations[] = $message['message'];
                    self::assertStringContainsString('BoundaryController must not depend on', $message['message']);
                    self::assertStringNotContainsString('BoundaryResource', $message['message']);
                    self::assertStringNotContainsString('LibraryReadScopeProviderInterface', $message['message']);
                    self::assertStringNotContainsString('AuthenticatedUserIdentityInterface', $message['message']);
                }
            }
            self::assertStringContainsString('BoundaryModel', implode('\n', $violations));
            self::assertStringContainsString('InternalApplicationService', implode('\n', $violations));
        } finally {
            $files->remove($directory);
        }
    }

    public function testTranscodeReachesQoLOnlyThroughPublishedContracts(): void
    {
        $report = $this->analyse(<<<'SOURCE'
<?php
namespace App\QoL\Domain\Port;
interface QualityLadderPortInterface {}
namespace App\QoL\Domain\Service;
final class InternalDomainService {}
final class BoundaryGovernor {
    public function ladder(\App\QoL\Domain\Port\QualityLadderPortInterface $ladder): void {}
}
namespace App\QoL\Application\Port;
interface StreamAdmissionPortInterface {
    public function admit(\App\Shared\Domain\Model\Uuid $jobId): void;
}
interface AllowedQualityTiersPortInterface {}
interface BudgetGuardInterface {
    public function guardDispatch(\App\Shared\Domain\Model\Uuid $jobId): void;
}
namespace App\QoL\Application;
final class InternalApplicationService {}
namespace App\QoL\Infrastructure;
final class BoundaryAdmission implements \App\QoL\Application\Port\StreamAdmissionPortInterface {
    public function admit(\App\Shared\Domain\Model\Uuid $jobId): void {}
}
final class BoundaryAllowedTiers implements \App\QoL\Application\Port\AllowedQualityTiersPortInterface {}
final class BoundaryBudgetGuard implements \App\QoL\Application\Port\BudgetGuardInterface {
    public function guardDispatch(\App\Shared\Domain\Model\Uuid $jobId): void {}
}
namespace App\Transcode\Infrastructure\QoL;
final class BoundaryLadder implements \App\QoL\Domain\Port\QualityLadderPortInterface {}
final class BoundaryListener {
    public function admit(
        \App\QoL\Application\Port\StreamAdmissionPortInterface $admission,
        \App\QoL\Application\Port\AllowedQualityTiersPortInterface $tiers,
    ): void {}
}
final class BoundaryViolation {
    public function internal(
        \App\QoL\Domain\Service\InternalDomainService $governor,
        \App\QoL\Application\InternalApplicationService $service,
    ): void {}
}
namespace App\Transcode\Application;
final class BoundaryViolation {
    public function contracts(
        \App\QoL\Application\Port\BudgetGuardInterface $guard,
        \App\QoL\Application\Port\StreamAdmissionPortInterface $admission,
    ): void {}
}
SOURCE);

        self::assertSame(0, $report['Report']['Errors']);
        self::assertSame(0, $report['Report']['Uncovered']);
        self::assertGreaterThan(0, $report['Report']['Allowed']);
        $violations = [];
        foreach ($report['files'] as $file) {
            foreach ($file['messages'] as $message) {
                $violations[] = $message['message'];
            }
        }
        sort($violations);
        self::assertSame([
            'App\Transcode\Application\BoundaryViolation must not depend on App\QoL\Application\Port\BudgetGuardInterface (Transcode Application on QoL Budget Guard Contract)',
            'App\Transcode\Application\BoundaryViolation must not depend on App\QoL\Application\Port\StreamAdmissionPortInterface (Transcode Application on QoL Stream Admission Contract)',
            'App\Transcode\Infrastructure\QoL\BoundaryViolation must not depend on App\QoL\Application\InternalApplicationService (Transcode Infrastructure on QoL Application)',
            'App\Transcode\Infrastructure\QoL\BoundaryViolation must not depend on App\QoL\Domain\Service\InternalDomainService (Transcode Infrastructure on QoL Domain)',
        ], $violations);
    }

    /** @return array{Report: array<string, int>, files: array<string, array{messages: list<array{message: string}>}>} */
    private function analyse(string $source): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander-boundary-' . bin2hex(random_bytes(8));
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

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $files->remove($directory);
        }
    }
}
