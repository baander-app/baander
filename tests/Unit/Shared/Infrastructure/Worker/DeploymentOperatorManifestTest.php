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
    public function testConfiguredSourceRootMustBelongToAnAdmittedSourceVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json());
        $recipe = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/media/library/audio']));
        self::assertSame('/media/library/audio', $recipe->runtimeEnvironment->variables['MEDIA_STORAGE_PATH']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source storage');
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/var/www/html/storage/media']));
    }

    public function testConfiguredSourceRootCannotEscapeItsVolume(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical absolute');
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['MEDIA_STORAGE_PATH' => '/media/library/../private']));
    }

    public function testRecipeBindsIdentityBudgetsAndFixedWorkerCommand(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json());
        $environment = new DeploymentRuntimeEnvironment(['APP_ENV' => 'prod']);
        $recipe = $manifest->recipe(DeploymentUnit::Control, $environment);
        self::assertSame([
            '/usr/local/bin/php', '-d', 'memory_limit=256M', '/var/www/html/bin/console', 'app:worker', '--no-interaction',
            '--deployment=operator.baander.app', '--boot-id=' . str_repeat('a', 32), '--unit=control',
            '--memory-mib=2944', '--pids-limit=129', '--scheduled-console-mib=0',
            '--autoscaling-profile=' . $manifest->autoscaling->toJson(),
            '--lock-dir=/tmp/baander-worker-locks', '--ipc-dir=/run/baander-worker',
        ], $recipe->command);
        self::assertSame(2944 * 1024 * 1024, $recipe->memoryBytes);
        self::assertSame($manifest->control->memoryBytes, $recipe->memoryBytes);
        self::assertSame($environment, $recipe->runtimeEnvironment);
        self::assertSame('/usr/bin/docker', $manifest->dockerBinary);
        self::assertSame('unix:///var/run/docker.sock', $manifest->dockerEndpoint);
        self::assertSame($recipe->fingerprint(), DeploymentOperatorManifest::fromJson($this->json())->recipe(DeploymentUnit::Control, $environment)->fingerprint());
        $arguments = $recipe->createArguments('/tmp/baander-worker-test.env');
        $memory = array_search('--memory', $arguments, true);
        self::assertNotFalse($memory);
        self::assertSame((string) $manifest->control->memoryBytes, $arguments[$memory + 1]);
    }

    public function testManifestMountsAreValidatedAndParticipateInCreateIdentity(): void
    {
        $mounts = self::mounts();
        $recipe = DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]))->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment());
        self::assertSame($mounts, $recipe->mediaMounts);
        self::assertContains('type=volume,src=baander-source,dst=/media/library,readonly,volume-nocopy', $recipe->createArguments());
        $mounts[0]['volume'] = 'replacement-source';
        self::assertNotSame($recipe->fingerprint(), DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]))->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment())->fingerprint());
        $mounts[0]['readOnly'] = false;
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]));
    }

    public function testManifestRejectsMissingSharedStateStorage(): void
    {
        $mounts = self::mounts();
        array_pop($mounts);
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]));
    }

    public function testManifestRejectsMissingSourceStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => array_slice(self::mounts(), 1)]));
    }

    public function testConversionPathMustBelongToWritableSharedStorage(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json());
        $recipe = $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/var/www/html/storage/conversions']));
        self::assertSame('/var/www/html/storage/conversions', $recipe->runtimeEnvironment->variables['CONVERT_STORAGE_PATH']);
        $this->expectException(InvalidArgumentException::class);
        $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/storage/conversions']));
    }

    public function testAlternateConversionPathUsesAnExplicitVolumeAtThatPath(): void
    {
        $mounts = [...self::mounts(), ['volume' => 'baander-conversions', 'target' => '/storage/conversions', 'readOnly' => false, 'role' => 'derived']];
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['mediaMounts' => $mounts]));
        self::assertContains('type=volume,src=baander-conversions,dst=/storage/conversions,volume-nocopy',
            $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment(['CONVERT_STORAGE_PATH' => '/storage/conversions']))->createArguments('/tmp/baander-test.env'));
    }

    /** @return list<array{volume:string,target:string,readOnly:bool,role:string}> */
    private static function mounts(): array
    {
        return [...DeploymentMediaMountsTest::mounts(), ['volume' => 'baander-state', 'target' => '/var/www/html/var/transcode_state', 'readOnly' => false, 'role' => 'derived']];
    }

    public function testDisabledScheduledConsoleAndMaximumMemoryAreAccepted(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['controlMemoryMiB' => 1048576, 'scheduledConsoleMiB' => 0]));
        self::assertSame(1024 * 1024 * 1024 * 1024, $manifest->control->memoryBytes);
        self::assertContains('--scheduled-console-mib=0', $manifest->recipe(DeploymentUnit::Control, new DeploymentRuntimeEnvironment())->command);
    }

    public function testDockerBinaryExistenceIsDeferredToExecutor(): void
    {
        $manifest = DeploymentOperatorManifest::fromJson($this->json(['dockerBinary' => '/nonexistent/baander-test-docker']));
        self::assertSame('/nonexistent/baander-test-docker', $manifest->dockerBinary);
    }

    public function testEveryFieldIsRequired(): void
    {
        $rejected = [];
        foreach (array_keys(self::fields()) as $field) {
            $fields = self::fields();
            unset($fields[$field]);
            try {
                DeploymentOperatorManifest::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
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
        DeploymentOperatorManifest::fromJson($this->json([$field => $value]));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidFields(): iterable
    {
        foreach (self::fields() as $field => $value) {
            yield $field . ' wrong scalar type' => [$field, is_int($value) ? (string) $value : 1];
            yield $field . ' null' => [$field, null];
            yield $field . ' structured value' => [$field, ['value' => $value]];
            if (is_int($value)) {
                yield $field . ' floating point' => [$field, $value + 0.5];
                yield $field . ' boolean' => [$field, true];
            }
        }
        yield 'unsupported version' => ['version', 1];
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
        foreach (['control', 'execution'] as $unit) {
            yield $unit . ' memory overflow' => [$unit . 'MemoryMiB', PHP_INT_MAX];
            yield $unit . ' oversized memory' => [$unit . 'MemoryMiB', 1048577];
            yield $unit . ' negative memory' => [$unit . 'MemoryMiB', -1];
            yield $unit . ' zero CPU' => [$unit . 'NanoCpus', 0];
            yield $unit . ' excess CPU' => [$unit . 'NanoCpus', 64000000001];
            yield $unit . ' excess PIDs' => [$unit . 'PidsLimit', 4097];
            yield $unit . ' empty affinity' => [$unit . 'CpuSet', []];
            yield $unit . ' negative affinity' => [$unit . 'CpuSet', [-1]];
            yield $unit . ' duplicate affinity' => [$unit . 'CpuSet', [4, 4]];
        }
        yield 'insufficient control memory' => ['controlMemoryMiB', 2303];
        yield 'insufficient execution memory' => ['executionMemoryMiB', 4095];
        yield 'insufficient control PIDs' => ['controlPidsLimit', 127];
        yield 'insufficient execution PIDs' => ['executionPidsLimit', 255];
        yield 'insufficient scheduled console' => ['scheduledConsoleMiB', 191];
        yield 'overlapping affinity' => ['executionCpuSet', [0]];
        yield 'IPC aliases media' => ['ipcVolume', 'baander-source'];

    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperatorManifest::fromJson($json);
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
        yield 'numeric overflow' => [str_replace('"version":4', '"version":9223372036854775808', json_encode(self::fields(), JSON_THROW_ON_ERROR))];
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
