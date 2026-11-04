<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;

use App\Shared\Infrastructure\Worker\DeploymentOperatorManifest;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentOperatorManifestTest extends TestCase
{
    public function testHouseholdManifestKeepsItsProfile(): void
    {
        $household = new \App\Shared\Application\DTO\DeploymentProfile('household');
        $manifest = DeploymentOperatorManifest::fromJson($this->json([
            'deploymentProfile' => json_decode($household->toJson(), true, flags: JSON_THROW_ON_ERROR),
        ]), '/var/www/html');
        self::assertEquals($household, $manifest->deploymentProfile);
    }

    public function testCreatedUnitsCarryNoResourceLimitAndTheirKillOrderScore(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html');
        foreach ([[DeploymentUnit::Control, -500], [DeploymentUnit::Execution, 300]] as [$unit, $score]) {
            $recipe = $manifest->recipe($unit, new DeploymentRuntimeEnvironment());
            self::assertSame($score, $recipe->oomScoreAdj);
            $arguments = $recipe->createArguments();
            foreach (['--memory', '--memory-swap', '--cpus', '--cpuset-cpus', '--cpu-shares', '--pids-limit'] as $limit) {
                self::assertNotContains($limit, $arguments);
            }
            self::assertSame([], array_values(array_filter($recipe->command, static fn (string $argument): bool => str_starts_with($argument, '--memory-mib')
                || str_starts_with($argument, '--pids-limit'))));
        }
    }

    public function testSavedManifestRemainsInspectableAfterItsSourceDirectoryDisappears(): void
    {
        $source = sys_get_temp_dir() . '/baander-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($source));
        $mounts = self::mounts();
        $mounts[0] = ['source' => $source, 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'];
        $json = $this->json(['sourceMountPolicy' => 'local-development', 'mediaMounts' => $mounts]);
        try {
            $before = DeploymentOperatorManifest::fromJson($json, '/var/www/html');
            $environment = new DeploymentRuntimeEnvironment();
            $fingerprint = $before->fingerprint($environment);
            self::assertTrue(rmdir($source));
            $saved = DeploymentOperatorManifest::fromJson($json, '/var/www/html');
            self::assertSame($fingerprint, $saved->fingerprint($environment));
            $this->expectException(InvalidArgumentException::class);
            $saved->recipe(DeploymentUnit::Control, $environment)->createArguments();
        } finally {
            if (is_dir($source)) {
                rmdir($source);
            }
        }
    }

    public function testObservedImageHomeDeterminesWorkerCommandAndWritableStorage(): void
    {
        $mounts = array_map(static function (array $mount): array {
            $mount['target'] = str_replace('/var/www/html', '/opt/baander', $mount['target']);
            return $mount;
        }, self::mounts());
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/opt/baander');
        $recipe = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment());
        self::assertContains('/opt/baander/bin/console', $recipe->command);
        self::assertSame('/opt/baander', $recipe->appHome);
    }

    public function testConfiguredSourceRootMustBelongToAnAdmittedSourceVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html');
        $recipe = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/media/library/audio']));
        self::assertSame('/media/library/audio', $recipe->runtimeEnvironment->variables['MEDIA_STORAGE_PATH']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source storage');
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/var/www/html/storage/media']));
    }

    public function testConfiguredSourceRootCannotEscapeItsVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical absolute');
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/media/library/../private']));
    }

    public function testRecipeBindsIdentityBudgetsAndFixedWorkerCommand(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html');
        $environment = new DeploymentRuntimeEnvironment(['APP_ENV' => 'prod']);
        $recipe = $manifest->recipe(DeploymentUnit::Control, $environment);
        self::assertSame([
            '/usr/local/bin/php', '-d', 'memory_limit=256M', '/var/www/html/bin/console', 'app:worker', '--no-interaction',
            '--deployment=operator.baander.app', '--boot-id=' . str_repeat('a', 32), '--unit=control',
            '--scheduled-console-mib=0',
            '--autoscaling-profile=' . $manifest->autoscaling->toJson(),
            '--deployment-profile=' . $manifest->deploymentProfile->toJson(),
            '--lock-dir=/tmp/baander-worker-locks', '--ipc-dir=/run/baander-worker',
        ], $recipe->command);
        self::assertSame($environment, $recipe->runtimeEnvironment);
        self::assertSame('/usr/bin/docker', $manifest->dockerBinary);
        self::assertSame('unix:///var/run/docker.sock', $manifest->dockerEndpoint);
        self::assertSame($recipe->fingerprint(), DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html')->recipe(DeploymentUnit::Control, $environment)->fingerprint());
        $arguments = $recipe->createArguments('/tmp/baander-worker-test.env');
        $score = array_search('--oom-score-adj', $arguments, true);
        self::assertNotFalse($score);
        self::assertSame('-500', $arguments[$score + 1]);
    }

    public function testManifestMountsAreValidatedAndParticipateInCreateIdentity(): void
    {
        $mounts = self::mounts();
        $recipe = DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/var/www/html')->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment());
        self::assertSame($mounts, $recipe->mediaMounts);
        self::assertContains('type=volume,src=baander-source,dst=/media/library,readonly,volume-nocopy', $recipe->createArguments());
        $mounts[0]['volume'] = 'replacement-source';
        self::assertNotSame($recipe->fingerprint(), DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/var/www/html')->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment())->fingerprint());
        $mounts[0]['readOnly'] = false;
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/var/www/html');
    }

    public function testManifestRejectsMissingSharedStateStorage(): void
    {
        $mounts = self::mounts();
        array_pop($mounts);
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/var/www/html');
    }

    public function testManifestRejectsMissingSourceStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => array_slice(self::mounts(), 1)]), '/var/www/html');
    }

    public function testConversionPathMustBelongToWritableSharedStorage(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(), '/var/www/html');
        $recipe = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/var/www/html/storage/conversions']));
        self::assertSame('/var/www/html/storage/conversions', $recipe->runtimeEnvironment->variables['CONVERT_STORAGE_PATH']);
        $this->expectException(InvalidArgumentException::class);
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/storage/conversions']));
    }

    public function testAlternateConversionPathUsesAnExplicitVolumeAtThatPath(): void
    {
        $mounts = [...self::mounts(), ['volume' => 'baander-conversions', 'target' => '/storage/conversions', 'readOnly' => false, 'role' => 'derived']];
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]), '/var/www/html');
        self::assertContains('type=volume,src=baander-conversions,dst=/storage/conversions,volume-nocopy',
            $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/storage/conversions']))->createArguments('/tmp/baander-test.env'));
    }

    public function testDevelopmentBindRequiresExplicitPolicyAndCanonicalReadOnlyDirectory(): void
    {
        $mounts = self::mounts();
        $mounts[0] = ['source' => '/tmp', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'];
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['version' => 8, 'sourceMountPolicy' => 'local-development', 'mediaMounts' => $mounts]), '/var/www/html');
        self::assertContains('type=bind,src=/tmp,dst=/media/library,readonly,bind-propagation=rprivate,bind-recursive=readonly', $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment())->createArguments());
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['version' => 8, 'sourceMountPolicy' => 'production', 'mediaMounts' => $mounts]), '/var/www/html');
    }

    /** @return list<array{volume:string,target:string,readOnly:bool,role:string}> */
    private static function mounts(): array
    {
        return [...DeploymentMediaMountsTest::mounts(), ['volume' => 'baander-state', 'target' => '/var/www/html/var/transcode_state', 'readOnly' => false, 'role' => 'derived']];
    }

    public function testDisabledAndMaximumScheduledConsoleReservationsAreAccepted(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['scheduledConsoleMiB' => 0]), '/var/www/html');
        self::assertContains('--scheduled-console-mib=0', $manifest->recipe(DeploymentUnit::Execution, new DeploymentRuntimeEnvironment())->command);
        $maximum = DeploymentOperatorManifest::fromJson($this->json(['scheduledConsoleMiB' => 1048576]), '/var/www/html');
        self::assertContains('--scheduled-console-mib=1048576', $maximum->recipe(DeploymentUnit::Execution, new DeploymentRuntimeEnvironment())->command);
    }

    public function testDockerBinaryExistenceIsDeferredToExecutor(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['dockerBinary' => '/nonexistent/baander-test-docker']), '/var/www/html');
        self::assertSame('/nonexistent/baander-test-docker', $manifest->dockerBinary);
    }

    public function testEveryFieldIsRequired(): void
    {
        $rejected = [];
        foreach (array_keys(self::fields()) as $field) {
            $fields = self::fields();
            unset($fields[$field]);
            try {
                DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR), '/var/www/html');
                self::fail('Missing field accepted: ' . $field);
            } catch (InvalidArgumentException) {
                $rejected[] = $field;
            }
        }
        self::assertSame(array_keys(self::fields()), $rejected);
    }

    #[DataProvider('invalidFields')]
    public function testInvalidFieldsAreRejected(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json([$field => $value]), '/var/www/html');
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidFields(): iterable
    {
        foreach (self::fields() as $field => $value) {
            yield $field . ' wrong scalar type' => [$field, is_int($value) ? (string) $value : 1];
            if ($value !== null) {
                yield $field . ' null' => [$field, null];
            }
            yield $field . ' structured value' => [$field, ['value' => $value]];
            if (is_int($value)) {
                yield $field . ' floating point' => [$field, $value + 0.5];
                yield $field . ' boolean' => [$field, true];
            }
        }
        yield 'unsupported version' => ['version', 1];
        yield 'obsolete manifest version' => ['version', 7];
        yield 'unknown source policy' => ['sourceMountPolicy', 'permissive'];
        yield 'unknown argv' => ['command', ['/bin/sh', '-c', 'arbitrary command']];
        yield 'unknown environment' => ['environment', ['APP_SECRET' => 'test-only-secret']];
        yield 'unknown lock path' => ['lockDirectory', '/tmp/other'];
        yield 'namespace injection' => ['namespace', 'operator.baander.app --help'];
        yield 'bad boot' => ['bootId', 'not-a-boot'];
        yield 'bad daemon' => ['daemonId', ''];
        yield 'mutable image' => ['imageId', 'baander.app:latest'];
        yield 'bad image digest' => ['imageId', 'sha256:abcd'];
        yield 'host network' => ['network', 'host'];
        yield 'remote endpoint' => ['dockerEndpoint', 'tcp://docker.baander.app:2376'];
        yield 'unix authority' => ['dockerEndpoint', 'unix://docker.baander.app/run/docker.sock'];
        yield 'relative unix path' => ['dockerEndpoint', 'unix://docker.sock'];
        yield 'empty unix path' => ['dockerEndpoint', 'unix:///'];
        yield 'endpoint NUL' => ['dockerEndpoint', "unix:///tmp/docker\0.sock"];
        yield 'endpoint newline' => ['dockerEndpoint', "unix:///tmp/docker\n.sock"];
        yield 'relative binary' => ['dockerBinary', 'docker'];
        yield 'empty binary' => ['dockerBinary', ''];
        yield 'binary NUL' => ['dockerBinary', "/usr/bin/docker\0"];
        yield 'insufficient scheduled console' => ['scheduledConsoleMiB', 319];
        yield 'oversized scheduled console' => ['scheduledConsoleMiB', 1048577];
        yield 'negative scheduled console' => ['scheduledConsoleMiB', -1];
        foreach (['controlMemoryMiB' => 2944, 'executionNanoCpus' => 1000000000, 'controlPidsLimit' => 129, 'executionCpuSet' => [1]] as $field => $value) {
            yield 'removed resource field ' . $field => [$field, $value];
        }
        yield 'IPC aliases media' => ['ipcVolume', 'baander-source'];

    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($json, '/var/www/html');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidJson(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['{"version":'];
        yield 'array' => ['[]'];
        yield 'scalar' => ['1'];
        yield 'null' => ['null'];
        yield 'oversized' => [str_repeat(' ', 8193)];
        yield 'excess depth' => [str_repeat('[', 9) . '0' . str_repeat(']', 9)];
        yield 'numeric overflow' => [str_replace('"version":8', '"version":9223372036854775808', json_encode(self::fields(), JSON_THROW_ON_ERROR))];
    }

    /** @param array<string,mixed> $overrides */
    private function json(array $overrides = []): string
    {
        return json_encode(array_replace(self::fields(), $overrides), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string,mixed> */
    private static function fields(): array
    {
        return array_replace(DeploymentPairManifestTest::fields(), ['mediaMounts' => self::mounts()]);
    }
}
