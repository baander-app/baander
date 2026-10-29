<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentOperatorManifest;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use PHPUnit\Framework\TestCase;

final class DeploymentPairManifestTest extends TestCase
{
    public function testEachUnitHasItsOwnEnforcedBudgetAndSharedPrivateSocketVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson(json_encode(self::fields(), JSON_THROW_ON_ERROR), '/var/www/html');
        $control = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment());
        $execution = $manifest->recipe(DeploymentUnit::Execution, new DeploymentRuntimeEnvironment());

        self::assertSame(2944 * 1024 * 1024, $control->memoryBytes);
        self::assertSame(4480 * 1024 * 1024, $execution->memoryBytes);
        self::assertSame([0], $control->cpuSet);
        self::assertSame([1, 2], $execution->cpuSet);
        self::assertSame(129, $control->pidsLimit);
        self::assertSame(256, $execution->pidsLimit);
        self::assertSame($control->bootId, $execution->bootId);
        self::assertNotSame($control->fingerprint(), $execution->fingerprint());
        self::assertContains('--unit=control', $control->command);
        self::assertContains('--unit=execution', $execution->command);
        self::assertContains('--scheduled-console-mib=0', $control->command);
        self::assertContains('--scheduled-console-mib=320', $execution->command);
        self::assertContains('--cpuset-cpus', $control->createArguments());
        self::assertContains('type=volume,src=worker-ipc,dst=/run/baander-worker,volume-nocopy', $control->createArguments());
    }

    public function testCpuSetsCannotOverlap(): void
    {
        $fields = self::fields();
        $fields['executionCpuSet'] = [0, 1];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disjoint');
        DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
    }

    public function testPolicyChangesAlterBothUnitRecipesAndFingerprint(): void
    {
        $fields = self::fields();
        $original = DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
        $fields['autoscaling']['targetBacklogAgeMs'] = 20000;
        $changed = DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
        $environment = new DeploymentRuntimeEnvironment();
        self::assertNotSame($original->fingerprint($environment), $changed->fingerprint($environment));
        foreach (DeploymentUnit::cases() as $unit) {
            self::assertNotSame($original->recipe($unit, $environment)->command, $changed->recipe($unit, $environment)->command);
        }
    }

    public function testMaximumPoolRequiresAllResidentReservationsBeforeLaunch(): void
    {
        $fields = self::fields();
        $fields['autoscaling']['maximumConcurrency'] = 4;
        $this->expectException(\InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
    }

    /** @return array<string,mixed> */
    public static function fields(): array
    {
        return [
            'version' => 5, 'sourceMountPolicy' => 'production', 'namespace' => 'operator.baander.app', 'bootId' => str_repeat('a', 32),
            'daemonId' => 'docker-daemon', 'imageId' => 'sha256:' . str_repeat('b', 64), 'network' => 'none',
            'dockerBinary' => '/usr/bin/docker', 'dockerEndpoint' => 'unix:///var/run/docker.sock',
            'controlMemoryMiB' => 2944, 'executionMemoryMiB' => 4480,
            'controlNanoCpus' => 1000000000, 'executionNanoCpus' => 2000000000,
            'controlCpuSet' => [0], 'executionCpuSet' => [1, 2],
            'controlPidsLimit' => 129, 'executionPidsLimit' => 256,
            'autoscaling' => json_decode((new \App\Shared\Application\DTO\WorkerAutoscalingProfile())->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'scheduledConsoleMiB' => 320, 'ipcVolume' => 'worker-ipc',
            'mediaMounts' => [
                ['volume' => 'library', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'],
                ['volume' => 'storage', 'target' => '/var/www/html/storage', 'readOnly' => false, 'role' => 'derived'],
                ['volume' => 'state', 'target' => '/var/www/html/var/transcode_state', 'readOnly' => false, 'role' => 'derived'],
            ],
        ];
    }
}
