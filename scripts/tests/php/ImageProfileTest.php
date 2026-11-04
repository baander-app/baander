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
            'deployment' => ['identifier' => 'household', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2, 'videoEncoderSlots' => 0, 'audioEncoderSlots' => 0, 'hardware' => null],
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
            $document = $this->runProgram($program, [$identifier, $root, '', '', '', '']);
            self::assertSame(['version', 'deployment', 'autoscaling', 'runtime'], array_keys($document));
            self::assertSame(['phpHeapMiB', 'phpProcessMemoryMiB', 'nativeManagerHeapMiB', 'nativeCommandLimit', 'nativeResultLimit', 'nativeClientLimit', 'mediaHelperLimit', 'scheduledConsoleMiB'], array_keys($document['runtime']));
            self::assertSame($document['runtime']['phpProcessMemoryMiB'], $document['runtime']['scheduledConsoleMiB']);
            $profile = ImageProfile::fromArray($document);
            self::assertSame($identifier, $profile->deployment['identifier']);
            self::assertSame(new \App\Shared\Application\DTO\DeploymentProfile($identifier)->webWorkers, $profile->webWorkers);
        }
    }

    public function testInImageProgramCarriesConfiguredSlotsAndTheirNativeLimits(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);

        $configured = ImageProfile::fromArray($this->runProgram($program, ['full', $root, '4', '0', '', '']));
        self::assertSame([4, 0], [$configured->deployment['videoEncoderSlots'], $configured->deployment['audioEncoderSlots']]);
        $expected = new \App\Shared\Application\DTO\DeploymentProfile('full', 4, 0);
        self::assertSame(
            [$expected->nativeCommandLimit(), $expected->nativeResultLimit(), $expected->nativeClientLimit()],
            [$configured->runtime['nativeCommandLimit'], $configured->runtime['nativeResultLimit'], $configured->runtime['nativeClientLimit']],
        );

        $defaults = ImageProfile::fromArray($this->runProgram($program, ['full', $root, '', '', '', '']));
        self::assertSame([8, 12], [$defaults->deployment['videoEncoderSlots'], $defaults->deployment['audioEncoderSlots']]);
        self::assertNotSame($defaults->fingerprint(), $configured->fingerprint());
    }

    public function testInImageProgramRejectsSlotsBeyondTheirBoundWithTheReason(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $program, 'full', dirname(__DIR__, 3), '146', '', '', '']), new CompletionPolicy([2]));
        } finally {
            $executor->close();
        }
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('at most 145 video slots', $result->stderr);
    }

    public function testInImageProgramCarriesTheHardwareEncoderAndItsVideoDefault(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);

        $hardware = ImageProfile::fromArray($this->runProgram($program, ['full', $root, '', '', 'vaapi', '/dev/dri/renderD128']));
        $expected = new \App\Shared\Application\DTO\DeploymentProfile('full', hardware: new \App\Shared\Application\DTO\HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $hardware->deployment['hardware']);
        self::assertSame($expected->videoEncoderSlots, $hardware->deployment['videoEncoderSlots']);
        self::assertEquals($expected, \App\Shared\Application\DTO\DeploymentProfile::fromJson(json_encode($hardware->deployment, JSON_THROW_ON_ERROR)));
        self::assertNotSame(ImageProfile::fromArray($this->runProgram($program, ['full', $root, '', '', '', '']))->fingerprint(), $hardware->fingerprint());

        $nvenc = ImageProfile::fromArray($this->runProgram($program, ['full', $root, '4', '12', 'nvenc', '']));
        self::assertSame(['accelerator' => 'nvenc', 'device' => null], $nvenc->deployment['hardware']);

        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $program, 'full', $root, '', '', 'vaapi', '']), new CompletionPolicy([2]));
        } finally {
            $executor->close();
        }
        self::assertStringContainsString('requires a device path', $result->stderr);
    }

    public function testHydrationRequiresAWellFormedHardwareEncoder(): void
    {
        foreach ([null, ['accelerator' => 'vaapi'], ['accelerator' => 'vaapi', 'device' => 128], 'vaapi'] as $hardware) {
            $document = $this->document();
            if ($hardware === null) {
                unset($document['deployment']['hardware']);
            } else {
                $document['deployment']['hardware'] = $hardware;
            }
            try {
                ImageProfile::fromArray($document);
                self::fail('Accepted a malformed hardware encoder.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<string> $arguments
     * @return array<string,mixed>
     */
    private function runProgram(string $program, array $arguments): array
    {
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $program, ...$arguments]), new CompletionPolicy([0]));
        } finally {
            $executor->close();
        }
        $document = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        return $document;
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
            self::assertSame(['household', '/srv/baander', '', '', '', ''], array_slice($created, -6));
            self::assertFileDoesNotExist($directory . '/resources.json');
            self::assertSame([], glob($directory . '/resource-*.json'));
        });
    }

    public function testResolvePassesConfiguredSlotsAndRejectsAnImageThatIgnoresThem(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            try {
                ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), 'household', $directory . '/resources.json', getenv(), 5, videoSlots: 3, audioSlots: 0);
                self::fail('Accepted a profile without the requested slots.');
            } catch (\RuntimeException $error) {
                self::assertSame('The image returned a different deployment profile.', $error->getMessage());
            }
            $created = null;
            foreach (file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $arguments = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($arguments[0] === 'create') {
                    $created = $arguments;
                }
            }
            self::assertIsArray($created);
            self::assertSame(['household', '/srv/baander', '3', '0', '', ''], array_slice($created, -6));
        });
    }

    public function testResolveRejectsNegativeSlotsBeforeRunningTheImage(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $this->expectException(\InvalidArgumentException::class);
            ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::load(), 'full', $directory . '/resources.json', getenv(), 5, audioSlots: -1);
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
