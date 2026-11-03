<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentOperatorManifest;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use PHPUnit\Framework\TestCase;

final class DeploymentPairManifestTest extends TestCase
{
    public function testEachUnitHasItsOwnKillOrderScoreAndSharedPrivateSocketVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson(json_encode(self::fields(), JSON_THROW_ON_ERROR), '/var/www/html');
        $control = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment());
        $execution = $manifest->recipe(DeploymentUnit::Execution, new DeploymentRuntimeEnvironment());

        self::assertSame(-500, $control->oomScoreAdj);
        self::assertSame(300, $execution->oomScoreAdj);
        self::assertSame($control->bootId, $execution->bootId);
        self::assertNotSame($control->fingerprint(), $execution->fingerprint());
        self::assertContains('--unit=control', $control->command);
        self::assertContains('--unit=execution', $execution->command);
        self::assertContains('--scheduled-console-mib=0', $control->command);
        self::assertContains('--scheduled-console-mib=320', $execution->command);
        self::assertNotContains('--cpuset-cpus', $control->createArguments());
        self::assertNotContains('--cpus', $execution->createArguments());
        self::assertContains('type=volume,src=worker-ipc,dst=/run/baander-worker,volume-nocopy', $control->createArguments());
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

    public function testOrdinaryPoolStaysWithinTheProfileConsumerLimit(): void
    {
        $fields = self::fields();
        $fields['autoscaling']['maximumConcurrency'] = 4;
        self::assertSame(4, DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html')->autoscaling->maximumConcurrency);
        $fields['deploymentProfile'] = json_decode((new \App\Shared\Application\DTO\DeploymentProfile('household'))->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $this->expectException(\InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
    }

    public function testDeploymentProfileChangesBothRecipeFingerprints(): void
    {
        $fields = self::fields();
        $full = DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
        $fields['deploymentProfile'] = json_decode((new \App\Shared\Application\DTO\DeploymentProfile('household'))->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $household = DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
        $environment = new DeploymentRuntimeEnvironment();
        foreach (DeploymentUnit::cases() as $unit) {
            self::assertNotSame($full->recipe($unit, $environment)->fingerprint(), $household->recipe($unit, new DeploymentRuntimeEnvironment(['BAANDER_DEPLOYMENT_PROFILE' => 'household']))->fingerprint());
        }
        $this->expectException(\InvalidArgumentException::class);
        $household->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['BAANDER_DEPLOYMENT_PROFILE' => 'full']));
    }

    public function testPreviousManifestVersionIsRejected(): void
    {
        $fields = self::fields();
        $fields['version'] = 6;
        $this->expectException(\InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
    }

    /** @return array<string,mixed> */
    public static function fields(): array
    {
        return [
            'version' => 7, 'sourceMountPolicy' => 'production', 'namespace' => 'operator.baander.app', 'bootId' => str_repeat('a', 32),
            'daemonId' => 'docker-daemon', 'imageId' => 'sha256:' . str_repeat('b', 64), 'network' => 'none',
            'dockerBinary' => '/usr/bin/docker', 'dockerEndpoint' => 'unix:///var/run/docker.sock',
            'deploymentProfile' => json_decode((new \App\Shared\Application\DTO\DeploymentProfile('full'))->toJson(), true, flags: JSON_THROW_ON_ERROR),
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
