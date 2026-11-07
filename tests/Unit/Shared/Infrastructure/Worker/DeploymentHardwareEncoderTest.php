<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Application\DTO\HardwareEncoder;
use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DeploymentOperatorManifest;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A configured hardware encoder reaches the execution unit, and only it, as a verified device and group. */
final class DeploymentHardwareEncoderTest extends TestCase
{
    private const string BOOT = '0123456789abcdef0123456789abcdef';
    private const string IMAGE = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string ID = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string DEVICE = '/dev/dri/renderD128';

    public function testExecutionAloneReceivesTheDeviceAndItsGroup(): void
    {
        $execution = $this->recipe(DeploymentUnit::Execution, self::vaapi(), 485)->createArguments();
        $control = $this->recipe(DeploymentUnit::Control, self::vaapi(), 485)->createArguments();

        self::assertSame(['--device', self::DEVICE . ':' . self::DEVICE . ':rwm'], self::option($execution, '--device'));
        self::assertSame(['--group-add', '485'], self::option($execution, '--group-add'));
        self::assertSame([], array_values(array_intersect($execution, ['--gpus', '--privileged', '--cap-add'])));
        self::assertSame([], array_values(array_intersect($control, ['--device', '--group-add', '--gpus'])));
        self::assertSame([], array_values(array_intersect($execution, ['--memory', '--memory-swap', '--cpus', '--cpuset-cpus', '--cpu-shares', '--pids-limit'])));
    }

    public function testNvencRequestsTheNvidiaGpusWithoutADeviceOrGroup(): void
    {
        $recipe = $this->recipe(DeploymentUnit::Execution, new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12, hardware: new HardwareEncoder('nvenc')));
        $arguments = $recipe->createArguments();

        self::assertSame(['--gpus', 'all,"capabilities=compute,utility,video"'], self::option($arguments, '--gpus'));
        self::assertSame([], array_values(array_intersect($arguments, ['--device', '--group-add'])));
        self::assertSame([], array_values(array_intersect($this->recipe(DeploymentUnit::Control, new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12, hardware: new HardwareEncoder('nvenc')))->createArguments(), ['--gpus'])));
    }

    public function testDeviceWithoutItsGroupIsRejectedForExecution(): void
    {
        $this->recipe(DeploymentUnit::Control, self::vaapi());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('numeric group');
        $this->recipe(DeploymentUnit::Execution, self::vaapi());
    }

    public function testDeviceAndGroupParticipateInTheExecutionFingerprint(): void
    {
        $software = $this->recipe(DeploymentUnit::Execution, new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12));
        $vaapi = $this->recipe(DeploymentUnit::Execution, self::vaapi(), 485);
        $otherGroup = $this->recipe(DeploymentUnit::Execution, self::vaapi(), 44);

        self::assertCount(3, array_unique([$software->fingerprint(), $vaapi->fingerprint(), $otherGroup->fingerprint()]));
        self::assertSame($vaapi->fingerprint(), $this->recipe(DeploymentUnit::Execution, self::vaapi(), 485)->fingerprint());
    }

    /** @param array<string,mixed> $inspection */
    #[DataProvider('inspectedDevices')]
    public function testInspectionMatchesOnlyTheAdmittedDeviceGroupAndGpus(DeploymentUnit $unit, ?string $accelerator, array $inspection, bool $matches): void
    {
        $profile = match ($accelerator) {
            null => new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12),
            'nvenc' => new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12, hardware: new HardwareEncoder('nvenc')),
            default => self::vaapi(),
        };
        $recipe = $this->recipe($unit, $profile, $accelerator === 'vaapi' ? 485 : null);
        self::assertSame($matches, $recipe->matchesDevices(array_replace(['devices' => null, 'groupAdd' => null, 'deviceRequests' => null], $inspection)));
    }

    /** @return iterable<string,array{DeploymentUnit,?string,array<string,mixed>,bool}> */
    public static function inspectedDevices(): iterable
    {
        $device = [['CgroupPermissions' => 'rwm', 'PathInContainer' => self::DEVICE, 'PathOnHost' => self::DEVICE]];
        $gpus = [['Capabilities' => [['compute', 'utility', 'video', 'gpu']], 'Count' => -1, 'DeviceIDs' => null, 'Driver' => '', 'Options' => []]];
        yield 'software without devices' => [DeploymentUnit::Execution, null, ['devices' => [], 'deviceRequests' => []], true];
        yield 'software with a device' => [DeploymentUnit::Execution, null, ['devices' => $device], false];
        yield 'execution with its device and group' => [DeploymentUnit::Execution, 'vaapi', ['devices' => $device, 'groupAdd' => ['485']], true];
        yield 'execution without its group' => [DeploymentUnit::Execution, 'vaapi', ['devices' => $device], false];
        yield 'execution with another group' => [DeploymentUnit::Execution, 'vaapi', ['devices' => $device, 'groupAdd' => ['44']], false];
        yield 'execution with another device' => [DeploymentUnit::Execution, 'vaapi', ['devices' => [['PathOnHost' => '/dev/sda', 'PathInContainer' => self::DEVICE, 'CgroupPermissions' => 'rwm']], 'groupAdd' => ['485']], false];
        yield 'execution with extra device metadata' => [DeploymentUnit::Execution, 'vaapi', ['devices' => [['PathOnHost' => self::DEVICE, 'PathInContainer' => self::DEVICE, 'CgroupPermissions' => 'rwm', 'Extra' => true]], 'groupAdd' => ['485']], false];
        yield 'execution with an extra device' => [DeploymentUnit::Execution, 'vaapi', ['devices' => [...$device, ...$device], 'groupAdd' => ['485']], false];
        yield 'execution with GPUs as well' => [DeploymentUnit::Execution, 'vaapi', ['devices' => $device, 'groupAdd' => ['485'], 'deviceRequests' => $gpus], false];
        yield 'control of a hardware profile without devices' => [DeploymentUnit::Control, 'vaapi', [], true];
        yield 'control of a hardware profile with the device' => [DeploymentUnit::Control, 'vaapi', ['devices' => $device, 'groupAdd' => ['485']], false];
        yield 'nvenc with all GPUs' => [DeploymentUnit::Execution, 'nvenc', ['deviceRequests' => $gpus], true];
        yield 'nvenc without capabilities' => [DeploymentUnit::Execution, 'nvenc', ['deviceRequests' => [['Capabilities' => [['gpu']], 'Count' => -1, 'DeviceIDs' => null, 'Driver' => '', 'Options' => []]]], false];
        yield 'malformed group list' => [DeploymentUnit::Execution, null, ['groupAdd' => 'render'], false];
    }

    public function testContainmentAdmitsTheExecutionDeviceAndRefusesItOnControl(): void
    {
        $device = [['CgroupPermissions' => 'rwm', 'PathInContainer' => self::DEVICE, 'PathOnHost' => self::DEVICE]];
        $execution = self::inspection(DeploymentUnit::Execution, ['devices' => $device, 'groupAdd' => ['485']]);
        (new DockerWorkerContainment(self::docker($execution), $this->recipe(DeploymentUnit::Execution, self::vaapi(), 485)))
            ->verifyIsolation(self::ID, 'recipe.baander.app', self::BOOT, DeploymentUnit::Execution);

        $control = self::inspection(DeploymentUnit::Control, ['devices' => $device, 'groupAdd' => ['485']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('devices, groups or GPU requests');
        (new DockerWorkerContainment(self::docker($control), $this->recipe(DeploymentUnit::Control, self::vaapi(), 485)))
            ->verifyIsolation(self::ID, 'recipe.baander.app', self::BOOT, DeploymentUnit::Control);
    }

    public function testContainmentRefusesAnExecutionUnitThatLostItsDevice(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('devices, groups or GPU requests');
        (new DockerWorkerContainment(self::docker(self::inspection(DeploymentUnit::Execution)), $this->recipe(DeploymentUnit::Execution, self::vaapi(), 485)))
            ->verifyIsolation(self::ID, 'recipe.baander.app', self::BOOT, DeploymentUnit::Execution);
    }

    public function testManifestTakesTheDeviceGroupFromTheHostOrItsOverride(): void
    {
        $fields = array_replace(DeploymentPairManifestTest::fields(), ['deploymentProfile' => json_decode(self::vaapi()->toJson(), true, flags: JSON_THROW_ON_ERROR)]);
        $resolved = [];
        $derived = DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html', static function (string $device) use (&$resolved): int {
            $resolved[] = $device;
            return 485;
        });
        self::assertSame([self::DEVICE], $resolved);
        self::assertSame(485, $derived->deviceGroup);
        self::assertSame(['--group-add', '485'], self::option($derived->recipe(DeploymentUnit::Execution, self::environment())->createArguments('/tmp/baander-test.env'), '--group-add'));
        self::assertSame([], self::option($derived->recipe(DeploymentUnit::Control, self::environment())->createArguments('/tmp/baander-test.env'), '--device'));

        $override = DeploymentOperatorManifest::fromJson(json_encode([...$fields, 'deviceGroup' => 44], JSON_THROW_ON_ERROR), '/var/www/html', static fn (): int => self::fail('An explicit group needs no host lookup.'));
        self::assertSame(44, $override->deviceGroup);
        self::assertNotSame($derived->fingerprint(self::environment()), $override->fingerprint(self::environment()));
    }

    public function testManifestRequiresADeviceGroupExactlyWithADevice(): void
    {
        $fields = array_replace(DeploymentPairManifestTest::fields(), ['deploymentProfile' => json_decode(self::vaapi()->toJson(), true, flags: JSON_THROW_ON_ERROR)]);
        try {
            DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
            self::fail('A device without a group or host lookup was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('device group', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('device group');
        DeploymentOperatorManifest::fromJson(json_encode([...DeploymentPairManifestTest::fields(), 'deviceGroup' => 485], JSON_THROW_ON_ERROR), '/var/www/html');
    }

    public function testHostDeviceGroupReadsADeviceNodeAndRefusesOtherPaths(): void
    {
        self::assertSame(stat('/dev/null')['gid'], DeploymentOperatorManifest::hostDeviceGroup('/dev/null'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a device node');
        DeploymentOperatorManifest::hostDeviceGroup('/dev/dri/baander-missing-render-node');
    }

    private static function vaapi(): DeploymentProfile
    {
        return new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12, hardware: new HardwareEncoder('vaapi', self::DEVICE));
    }

    private function recipe(DeploymentUnit $unit, DeploymentProfile $profile, ?int $deviceGroup = null): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('recipe.baander.app', self::BOOT, 'docker-daemon', self::IMAGE, ['/usr/local/bin/php', '/app/bin/worker.php'],
            'none', $unit, 'worker-ipc', '/var/www/html', $profile, deviceGroup: $deviceGroup);
    }

    private static function environment(): DeploymentRuntimeEnvironment
    {
        return new DeploymentRuntimeEnvironment(['BAANDER_DEPLOYMENT_PROFILE' => self::vaapi()->toJson()]);
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private static function option(array $arguments, string $name): array
    {
        $values = [];
        foreach ($arguments as $index => $argument) {
            if ($argument === $name) {
                array_push($values, $name, $arguments[$index + 1]);
            }
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function inspection(DeploymentUnit $unit, array $overrides = []): array
    {
        return array_replace(['id' => self::ID, 'namespace' => 'recipe.baander.app', 'bootId' => self::BOOT, 'role' => $unit->value,
            'memory' => 0, 'memorySwap' => 0, 'nanoCpus' => 0, 'cpuQuota' => 0, 'cpuShares' => 0, 'pidsLimit' => null, 'cpuSet' => '',
            'oomScoreAdj' => $unit === DeploymentUnit::Control ? -500 : 300, 'restart' => 'no', 'retryCount' => 0,
            'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private',
            'mounts' => [['Type' => 'volume', 'Name' => 'worker-ipc', 'Destination' => '/run/baander-worker', 'Driver' => 'local', 'RW' => true, 'Source' => '/var/lib/docker/volumes/worker-ipc/_data']],
            'binds' => null, 'tmpfs' => null, 'devices' => [], 'deviceRequests' => null, 'groupAdd' => null, 'capAdd' => null,
            'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges']], $overrides);
    }

    /** @param array<string,mixed> $inspection */
    private static function docker(array $inspection): \Closure
    {
        return static function (array $arguments) use ($inspection): string {
            return match ($arguments[0]) {
                'container' => json_encode($inspection, JSON_THROW_ON_ERROR),
                'volume' => json_encode(['name' => 'worker-ipc', 'driver' => 'local', 'options' => null, 'scope' => 'local',
                    'mountpoint' => '/var/lib/docker/volumes/worker-ipc/_data'], JSON_THROW_ON_ERROR),
                default => throw new \LogicException('Unexpected Docker call.'),
            };
        };
    }
}
