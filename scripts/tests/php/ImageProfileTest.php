<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\{ContainerMeasurement, ImageProfile};
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec};
use App\Shared\Application\DTO\{DeploymentProfile, HardwareEncoder};
use PHPUnit\Framework\TestCase;

final class ImageProfileTest extends TestCase
{
    /** @return array<string,mixed> The profile a remux image reports for two web workers and two copy slots. */
    private function document(): array
    {
        return [
            'version' => 2,
            'deployment' => ['capability' => 'remux', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2, 'videoEncoderSlots' => 0, 'audioEncoderSlots' => 0, 'hardware' => null],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'phpProcessMemoryMiB' => 320, 'nativeManagerHeapMiB' => 128, 'nativeCommandLimit' => 5,
                'nativeResultLimit' => 12, 'nativeClientLimit' => 4, 'mediaHelperLimit' => 1, 'scheduledConsoleMiB' => 320],
        ];
    }

    /**
     * Settings for the remux deployment the fixture image reports, with overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array{capability:string,webWorkers:int,doctrinePoolSlots:int,ordinaryConsumerLimit:int,sharedCopySlots:int,videoEncoderSlots:?int,audioEncoderSlots:?int,hardware:array{accelerator:string,device:?string}|null}
     */
    private static function remuxSettings(array $overrides = []): array
    {
        /** @var array{capability:string,webWorkers:int,doctrinePoolSlots:int,ordinaryConsumerLimit:int,sharedCopySlots:int,videoEncoderSlots:?int,audioEncoderSlots:?int,hardware:array{accelerator:string,device:?string}|null} */
        return array_replace(['capability' => 'remux', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1,
            'sharedCopySlots' => 2, 'videoEncoderSlots' => null, 'audioEncoderSlots' => null, 'hardware' => null], $overrides);
    }

    /**
     * The default transcode settings with overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function transcodeSettings(array $overrides = []): array
    {
        return array_replace(ImageProfile::DEFAULT_SETTINGS, $overrides);
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
        self::assertSame('remux', $profile->deployment['capability']);
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

    public function testMissingRuntimeValueCannotFallBackToTranscodeDefaults(): void
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

    public function testEarlierProfileDocumentsCannotBeHydrated(): void
    {
        $document = $this->document();
        $document['version'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testInImageProgramReportsTheApplicationProfile(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);
        foreach ([
            'remux' => [self::remuxSettings(), new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)],
            'transcode' => [self::transcodeSettings(), new DeploymentProfile()],
        ] as $capability => [$settings, $expected]) {
            $document = $this->runProgram($program, [json_encode($settings, JSON_THROW_ON_ERROR), $root]);
            self::assertSame(['version', 'deployment', 'autoscaling', 'runtime'], array_keys($document));
            self::assertSame(2, $document['version']);
            self::assertSame(['phpHeapMiB', 'phpProcessMemoryMiB', 'nativeManagerHeapMiB', 'nativeCommandLimit', 'nativeResultLimit', 'nativeClientLimit', 'mediaHelperLimit', 'scheduledConsoleMiB'], array_keys($document['runtime']));
            self::assertSame($document['runtime']['phpProcessMemoryMiB'], $document['runtime']['scheduledConsoleMiB']);
            $profile = ImageProfile::fromArray($document);
            self::assertSame($capability, $profile->deployment['capability']);
            self::assertSame($expected->webWorkers, $profile->webWorkers);
            self::assertEquals($expected, DeploymentProfile::fromJson(json_encode($document['deployment'], JSON_THROW_ON_ERROR)));
        }
    }

    public function testInImageProgramCarriesConfiguredSlotsAndTheirNativeLimits(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);

        $configured = ImageProfile::fromArray($this->runProgram($program, [json_encode(self::transcodeSettings(['videoEncoderSlots' => 4, 'audioEncoderSlots' => 0]), JSON_THROW_ON_ERROR), $root]));
        self::assertSame([4, 0], [$configured->deployment['videoEncoderSlots'], $configured->deployment['audioEncoderSlots']]);
        $expected = new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 0);
        self::assertSame(
            [$expected->nativeCommandLimit(), $expected->nativeResultLimit(), $expected->nativeClientLimit()],
            [$configured->runtime['nativeCommandLimit'], $configured->runtime['nativeResultLimit'], $configured->runtime['nativeClientLimit']],
        );

        $defaults = ImageProfile::fromArray($this->runProgram($program, [json_encode(self::transcodeSettings(), JSON_THROW_ON_ERROR), $root]));
        self::assertSame([8, 12], [$defaults->deployment['videoEncoderSlots'], $defaults->deployment['audioEncoderSlots']]);
        self::assertNotSame($defaults->fingerprint(), $configured->fingerprint());
    }

    public function testInImageProgramRejectsSettingsBeyondTheirBoundWithTheReason(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        foreach ([
            [self::transcodeSettings(['videoEncoderSlots' => 146]), 'at most 145 video slots'],
            [self::transcodeSettings(['webWorkers' => 17]), 'between 1 and 16 web workers'],
            [self::remuxSettings(['sharedCopySlots' => 9]), 'between 1 and 8 copy slots'],
        ] as [$settings, $reason]) {
            $result = $this->runFailingProgram($program, [json_encode($settings, JSON_THROW_ON_ERROR), dirname(__DIR__, 3)]);
            self::assertSame('', $result->stdout);
            self::assertStringContainsString($reason, $result->stderr);
        }
    }

    public function testInImageProgramCarriesTheHardwareEncoderAndItsVideoDefault(): void
    {
        $program = new \ReflectionClassConstant(ImageProfile::class, 'PROGRAM')->getValue();
        self::assertIsString($program);
        $root = dirname(__DIR__, 3);
        $vaapi = ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'];

        $hardware = ImageProfile::fromArray($this->runProgram($program, [json_encode(self::transcodeSettings(['hardware' => $vaapi]), JSON_THROW_ON_ERROR), $root]));
        $expected = new DeploymentProfile(hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        self::assertSame($vaapi, $hardware->deployment['hardware']);
        self::assertSame($expected->videoEncoderSlots, $hardware->deployment['videoEncoderSlots']);
        self::assertEquals($expected, DeploymentProfile::fromJson(json_encode($hardware->deployment, JSON_THROW_ON_ERROR)));
        self::assertNotSame(ImageProfile::fromArray($this->runProgram($program, [json_encode(self::transcodeSettings(), JSON_THROW_ON_ERROR), $root]))->fingerprint(), $hardware->fingerprint());

        $nvenc = ImageProfile::fromArray($this->runProgram($program, [json_encode(self::transcodeSettings(['videoEncoderSlots' => 4, 'audioEncoderSlots' => 12, 'hardware' => ['accelerator' => 'nvenc', 'device' => null]]), JSON_THROW_ON_ERROR), $root]));
        self::assertSame(['accelerator' => 'nvenc', 'device' => null], $nvenc->deployment['hardware']);

        $missingDevice = $this->runFailingProgram($program, [json_encode(self::transcodeSettings(['hardware' => ['accelerator' => 'vaapi', 'device' => null]]), JSON_THROW_ON_ERROR), $root]);
        self::assertStringContainsString('requires a device path', $missingDevice->stderr);
        $remux = $this->runFailingProgram($program, [json_encode(self::remuxSettings(['hardware' => $vaapi]), JSON_THROW_ON_ERROR), $root]);
        self::assertStringContainsString('no hardware encoder', $remux->stderr);
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

    /** @param list<string> $arguments */
    private function runFailingProgram(string $program, array $arguments): ProcessResult
    {
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            return $executor->run(new ProcessSpec([PHP_BINARY, '-r', $program, ...$arguments]), new CompletionPolicy([2]));
        } finally {
            $executor->close();
        }
    }

    public function testUnknownCapabilityCannotBeHydrated(): void
    {
        $document = $this->document();
        $document['deployment']['capability'] = 'unknown';
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testResolveUsesTheCapturedDaemonAndImmutableImageWithoutNetwork(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $image = 'sha256:' . str_repeat('a', 64);
            $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
            $measurement = ContainerMeasurement::prepare(['directory' => $directory . '/measurements', 'namespace' => 'fixture', 'boot_id' => 'owner'], 'profile-application', $image);
            $profile = ImageProfile::resolve($docker, $image, $layout, self::remuxSettings(), $directory . '/resources.json', getenv(), 5, measurement: $measurement);
            self::assertSame(320, $profile->scheduledConsoleMiB);
            $created = $this->created($directory);
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
            self::assertSame([json_encode(self::remuxSettings(), JSON_THROW_ON_ERROR), '/srv/baander'], array_slice($created, -2));
            self::assertFileDoesNotExist($directory . '/resources.json');
            self::assertSame([], glob($directory . '/resource-*.json'));
        });
    }

    public function testResolveRejectsAnImageThatEchoesOtherSettings(): void
    {
        foreach ([
            'copy slots' => self::remuxSettings(['sharedCopySlots' => 3]),
            'web workers' => self::remuxSettings(['webWorkers' => 3]),
            'video slots' => self::remuxSettings(['videoEncoderSlots' => 1]),
            'capability' => self::transcodeSettings(['webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1]),
            'hardware' => self::transcodeSettings(['hardware' => ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128']]),
        ] as $case => $settings) {
            $this->withDocker(function (Client $docker, string $directory) use ($case, $settings): void {
                try {
                    ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $settings, $directory . '/resources.json', getenv(), 5);
                    self::fail('Accepted an image profile with other ' . $case . '.');
                } catch (\RuntimeException $error) {
                    self::assertSame('The image returned a different deployment profile.', $error->getMessage(), $case);
                }
                self::assertSame(json_encode($settings, JSON_THROW_ON_ERROR), array_slice($this->created($directory), -2)[0], $case);
            });
        }
    }

    public function testResolveRejectsAnUnknownCapabilityBeforeRunningTheImage(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            try {
                ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::load(), self::remuxSettings(['capability' => 'unknown']), $directory . '/resources.json', getenv(), 5);
                self::fail('Resolved an unknown capability.');
            } catch (\InvalidArgumentException) {
                self::assertFileDoesNotExist($directory . '/calls.jsonl');
            }
        });
    }

    /** @return list<string> The arguments of the fixture daemon's container creation. */
    private function created(string $directory): array
    {
        $created = null;
        foreach (file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $arguments = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($arguments[0] === 'create') {
                $created = $arguments;
            }
        }
        self::assertIsArray($created);
        return $created;
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
