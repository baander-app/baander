<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\{ContainerMeasurement, ImageProfile};
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class ImageProfileTest extends TestCase
{
    /** @return array<string,mixed> */
    private function document(): array
    {
        return [
            'version' => 1,
            'deployment' => ['identifier' => 'household', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2, 'videoEncoderSlots' => 0, 'audioEncoderSlots' => 0],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'phpProcessMemoryMiB' => 320, 'nativeManagerHeapMiB' => 128, 'nativeCommandLimit' => 5,
                'nativeResultLimit' => 12, 'nativeClientLimit' => 4, 'mediaHelperLimit' => 1, 'scheduledConsoleMiB' => 320],
        ];
    }

    public function testImageValuesSurvivePersistenceWithoutRecalculation(): void
    {
        $document = $this->document();
        $document['runtime']['scheduledConsoleMiB'] = 300;
        $profile = ImageProfile::fromArray($document);
        self::assertSame(300, $profile->scheduledConsoleMiB);
        self::assertSame(2, $profile->webWorkers);
        self::assertSame(4, $profile->doctrinePoolSlots);
        self::assertSame(256, $profile->phpHeapMiB);
        self::assertSame($profile->fingerprint(), ImageProfile::fromArray($profile->toArray())->fingerprint());
    }

    public function testFingerprintIncludesNativeLimitsButIgnoresObjectKeyOrder(): void
    {
        $document = $this->document();
        $fingerprint = ImageProfile::fromArray($document)->fingerprint();
        $document['runtime'] = array_reverse($document['runtime'], true);
        self::assertSame($fingerprint, ImageProfile::fromArray($document)->fingerprint());
        $document['runtime']['nativeCommandLimit'] = 4;
        self::assertNotSame($fingerprint, ImageProfile::fromArray($document)->fingerprint());
    }

    public function testMissingRuntimeValueCannotFallBackToFullDefaults(): void
    {
        foreach (['phpHeapMiB', 'scheduledConsoleMiB'] as $key) {
            $document = $this->document();
            unset($document['runtime'][$key]);
            try {
                ImageProfile::fromArray($document);
                self::fail('Accepted a profile without ' . $key . '.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($key, $error->getMessage());
            }
        }
    }

    public function testContainerSizingBudgetsAreNotPartOfTheProfile(): void
    {
        $document = $this->document();
        $document['budgets'] = ['controlMemoryMiB' => 2304];
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testInImageProgramReportsTheApplicationProfile(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);
        foreach (['household', 'full'] as $identifier) {
            $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
            try {
                $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $program, $identifier, $root]), new CompletionPolicy([0]));
            } finally {
                $executor->close();
            }
            $document = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            self::assertSame(['version', 'deployment', 'autoscaling', 'runtime'], array_keys($document));
            self::assertSame(['phpHeapMiB', 'phpProcessMemoryMiB', 'nativeManagerHeapMiB', 'nativeCommandLimit', 'nativeResultLimit', 'nativeClientLimit', 'mediaHelperLimit', 'scheduledConsoleMiB'], array_keys($document['runtime']));
            self::assertSame($document['runtime']['phpProcessMemoryMiB'], $document['runtime']['scheduledConsoleMiB']);
            $profile = ImageProfile::fromArray($document);
            self::assertSame($identifier, $profile->deployment['identifier']);
            self::assertSame(new \App\Shared\Application\DTO\DeploymentProfile($identifier)->webWorkers, $profile->webWorkers);
        }
    }

    public function testUnknownProfileCannotBeHydrated(): void
    {
        $document = $this->document();
        $document['deployment']['identifier'] = 'unknown';
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testResolveUsesTheCapturedDaemonAndImmutableImageWithoutNetwork(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $image = 'sha256:' . str_repeat('a', 64);
            $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
            $measurement = ContainerMeasurement::prepare(['directory' => $directory . '/measurements', 'namespace' => 'fixture', 'boot_id' => 'owner'], 'profile-application', $image);
            $profile = ImageProfile::resolve($docker, $image, $layout, 'household', $directory . '/resources.json', getenv(), 5, measurement: $measurement);
            self::assertSame(320, $profile->scheduledConsoleMiB);
            $calls = file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($calls);
            $created = null;
            foreach ($calls as $line) {
                $arguments = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($arguments[0] === 'create') {
                    $created = $arguments;
                }
            }
            self::assertIsArray($created);
            self::assertContains($image, $created);
            self::assertSame('none', $created[array_search('--network', $created, true) + 1]);
            self::assertContains('--read-only', $created);
            self::assertContains('--cgroupns', $created);
            self::assertStringContainsString('BAANDER_OPERATOR_MEASURE_V1', implode(' ', $created));
            self::assertSame('incomplete', AtomicFile::readJson($measurement['path'])['status']);
            foreach (['--memory', '--memory-swap', '--pids-limit', '--cpus', '--cpuset-cpus'] as $limit) {
                self::assertNotContains($limit, $created);
            }
            self::assertSame('/srv/baander', $created[array_search('--workdir', $created, true) + 1]);
            self::assertSame(['household', '/srv/baander'], array_slice($created, -2));
            self::assertFileDoesNotExist($directory . '/resources.json');
            self::assertSame([], glob($directory . '/resource-*.json'));
        });
    }

    public function testResolveRejectsAnImageReturningAnotherProfile(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $this->expectException(\RuntimeException::class);
            ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::load(), 'full', $directory . '/resources.json', getenv(), 5);
        });
    }

    private function withDocker(\Closure $test): void
    {
        $directory = sys_get_temp_dir() . '/baander-image-profile-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/daemon.json', ['stdout' => json_encode($this->document(), JSON_THROW_ON_ERROR)]);
        $executor = new EngineExecutor(ExecutionContext::bounded(10, getenv()));
        $docker = new Client($executor, new Target($directory . '/docker', 'unix:///baander.app/fixture.sock', 'owned-daemon', null, null), getenv());
        try {
            $test($docker, $directory);
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }
}
