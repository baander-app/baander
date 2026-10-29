<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Target;
use App\Shared\Infrastructure\Worker\{DeploymentOperatorCredentials, DeploymentOperatorManifest, DeploymentRuntimeEnvironment};
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Worker\{OperatorCommands, OperatorConfiguration, OperatorProvisioning, OperatorQualification, OperatorResources, OperatorRun, OperatorTopology};
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Fixtures\Runtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerOperatorBuildTest extends TestCase
{
    public function testProvisioningBuildsFromOwnedAliasesAndKeepsImmutableRuntimeIdentities(): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-build-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        mkdir($directory . '/work', 0700);
        $base = 'sha256:' . str_repeat('a', 64);
        $helper = 'sha256:' . str_repeat('b', 64);
        AtomicFile::writeJson($directory . '/state.json', ['daemonId' => 'image-daemon', 'mode' => 'clean', 'calls' => [], 'containers' => [], 'images' => [
            'fixture-base:original' => ['Id' => $base, 'Config' => ['Labels' => []]],
            'fixture-base:other' => ['Id' => $base, 'Config' => ['Labels' => []]],
            'fixture-helper:original' => ['Id' => $helper, 'Config' => ['Labels' => []]],
        ]]);
        AtomicFile::writeJson($directory . '/target.json', new Target(__DIR__ . '/Fixtures/image-ledger-docker.php', 'unix:///baander.app/docker.sock', 'image-daemon', null, null)->toArray());
        $environment = array_replace(getenv(), [
            'BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json',
            'BAANDER_DOCKER_EXECUTABLE' => $directory . '/ambient-docker-must-not-run',
            'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
            'BAANDER_TEST_DOCKER_CLI_IMAGE' => 'fixture-helper:original',
            'BUILDX_BUILDER' => 'foreign-builder-must-not-run',
            'BUILDKIT_HOST' => 'tcp://foreign-builder.baander.app:1234',
            'IMAGE_LEDGER_STATE' => $directory . '/state.json',
            'IMAGE_LEDGER_RECEIPT' => $directory . '/artifacts/images.json',
        ]);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment, 120);
        $resources = new OperatorResources($commands, $directory . '/artifacts');
        $state = new OperatorRun();
        $state->work = $directory . '/work';
        $state->artifacts = $directory . '/artifacts';
        $state->runId = 'baander-build-probe';
        $state->appHome = '/app';
        $state->baseId = $base;
        $state->fixtureBase = $base;
        $state->fixtureImage = 'baander-build-probe:fixture';
        $state->controllerImage = 'baander-build-probe:controller';
        $reflection = new \ReflectionClass(OperatorQualification::class);
        $qualification = $reflection->newInstanceWithoutConstructor();
        foreach (['root' => dirname(__DIR__, 3), 'state' => $state, 'commands' => $commands, 'resources' => $resources, 'configuration' => new OperatorConfiguration($environment, $commands->runtime->configuration)] as $name => $value) {
            $reflection->getProperty($name)->setValue($qualification, $value);
        }
        $provisioning = new OperatorProvisioning($qualification);
        try {
            new \ReflectionMethod($provisioning, 'buildFixture')->invoke($provisioning);
            $fixtureId = trim($commands->docker(['image', 'inspect', '--format', '{{.Id}}', $state->fixtureImage])->stdout);
            new \ReflectionMethod($provisioning, 'buildController')->invoke($provisioning, $fixtureId);
            $evidence = AtomicFile::readJson($directory . '/artifacts/build-inputs.json');
            self::assertSame(1, $evidence['version']);
            self::assertSame('image-daemon', $evidence['daemonId']);
            self::assertSame($base, $evidence['inputs']['fixture-base']['id']);
            self::assertSame($fixtureId, $evidence['inputs']['controller-base']['id']);
            self::assertSame($helper, $evidence['inputs']['docker-cli']['id']);
            $fake = AtomicFile::readJson($directory . '/state.json');
            self::assertCount(2, $fake['dockerfiles']);
            foreach ($fake['dockerfiles'] as $dockerfile) {
                self::assertStringNotContainsString('%APP_HOME%', $dockerfile);
            }
            self::assertCount(2, $fake['buildEnvironments']);
            foreach ($fake['buildEnvironments'] as $buildEnvironment) {
                self::assertEmpty($buildEnvironment['BUILDX_BUILDER']);
                self::assertEmpty($buildEnvironment['BUILDKIT_HOST']);
            }
            self::assertSame('foreign-builder-must-not-run', $commands->environment['BUILDX_BUILDER']);
            self::assertSame('tcp://foreign-builder.baander.app:1234', $commands->environment['BUILDKIT_HOST']);
            foreach ([['FIXTURE_BASE', 'fixture-base', $base, 0], ['CONTROLLER_BASE', 'controller-base', $fixtureId, 1], ['DOCKER_CLI', 'docker-cli', $helper, 1]] as [$argument, $role, $id, $build]) {
                self::assertSame($id, $fake['builds'][$build][$argument]['id']);
                self::assertSame($evidence['inputs'][$role]['reference'], $fake['builds'][$build][$argument]['reference']);
                self::assertStringStartsWith('baander-build-input-', $fake['builds'][$build][$argument]['reference']);
            }
            self::assertSame($base, $state->baseId);
            self::assertSame($base, $state->fixtureBase);
            self::assertSame($helper, $state->controllerCli);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $state->controllerId);
            self::assertSame([], $resources->cleanup());
            self::assertSame($evidence, AtomicFile::readJson($directory . '/artifacts/build-inputs.json'));
            self::assertSame(['fixture-base:original', 'fixture-base:other', 'fixture-helper:original'], array_keys(AtomicFile::readJson($directory . '/state.json')['images']));
        } finally {
            $resources->cleanup();
            unset($resources);
            $commands->close();
            Collector::remove($directory);
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function retargetedBuilds(): iterable
    {
        yield 'successful build with altered alias' => ['retarget-during-build', 1];
        yield 'failed build with altered alias' => ['retarget-during-build-failure', 37];
    }

    #[DataProvider('retargetedBuilds')]
    public function testBuildPostcheckRejectsRetargetingAndPreservesPrimaryFailure(string $mode, int $status): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-retarget-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        $id = 'sha256:' . str_repeat('a', 64);
        AtomicFile::writeJson($directory . '/state.json', ['daemonId' => 'image-daemon', 'mode' => $mode, 'calls' => [], 'containers' => [], 'images' => ['fixture-base:original' => ['Id' => $id, 'Config' => ['Labels' => []]]]]);
        AtomicFile::writeJson($directory . '/target.json', new Target(__DIR__ . '/Fixtures/image-ledger-docker.php', 'unix:///baander.app/docker.sock', 'image-daemon', null, null)->toArray());
        $environment = array_replace(getenv(), ['BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json', 'IMAGE_LEDGER_STATE' => $directory . '/state.json', 'IMAGE_LEDGER_RECEIPT' => $directory . '/artifacts/images.json']);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment, 120);
        $resources = new OperatorResources($commands, $directory . '/artifacts');
        try {
            $reference = $resources->buildInput('fixture-base', $id);
            try {
                $resources->buildImage('baander-build-probe:output', ['--build-arg', 'FIXTURE_BASE=' . $reference, $directory], $directory . '/artifacts/build.log');
                self::fail('Expected altered build input refusal');
            } catch (\RuntimeException $error) {
                self::assertSame($status, Runtime::status($error));
                self::assertStringContainsString($reference, Runtime::failure($error));
                if ($status === 37) {
                    self::assertStringContainsString('37', Runtime::failure($error));
                }
            }
            self::assertNotEmpty($resources->cleanup());
            self::assertFileExists($directory . '/artifacts/images.json');
            self::assertFileExists($directory . '/artifacts/build-inputs.json');
            self::assertSame('sha256:' . str_repeat('c', 64), AtomicFile::readJson($directory . '/state.json')['images'][$reference]['Id']);
        } finally {
            unset($resources);
            $commands->close();
            Collector::remove($directory);
        }
    }


    /** @return iterable<string,array{string}> */
    public static function applicationRoots(): iterable
    {
        yield 'default application root' => [ApplicationLayout::load()->root];
        yield 'alternate application root' => ['/opt/baander'];
    }

    #[DataProvider('applicationRoots')]
    public function testMediaPreparationRendersTheSelectedApplicationRoot(string $appHome): void
    {
        $this->assertMediaPreparation($appHome, false);
    }

    #[DataProvider('applicationRoots')]
    public function testGeneratedCredentialsAreAcceptedByTheProductionFileParser(string $appHome): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('posix_geteuid unavailable; run real credentials parser in CI PHP.');
        }
        $this->assertMediaPreparation($appHome, true);
    }

    private function assertMediaPreparation(string $appHome, bool $parseCredentials): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-layout-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        mkdir($directory . '/work', 0700);
        file_put_contents($directory . '/work/runtime.env', "APP_ENV=test\n");
        AtomicFile::writeJson($directory . '/state.json', ['daemonId' => 'image-daemon', 'mode' => 'clean', 'calls' => [], 'containers' => [], 'images' => []]);
        AtomicFile::writeJson($directory . '/target.json', new Target(__DIR__ . '/Fixtures/image-ledger-docker.php', 'unix:///baander.app/docker.sock', 'image-daemon', null, null)->toArray());
        $environment = array_replace(getenv(), ['BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json', 'IMAGE_LEDGER_STATE' => $directory . '/state.json']);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment, 120);
        $resources = new OperatorResources($commands, $directory . '/artifacts');
        $state = new OperatorRun();
        $state->appHome = $appHome;
        $state->runId = 'baander-layout-probe';
        $state->work = $directory . '/work';
        $state->artifacts = $directory . '/artifacts';
        $state->imageId = 'sha256:' . str_repeat('a', 64);
        $state->namespace = 'layout-probe';
        $state->boot = 'layout-boot';
        foreach (['sourceVolume', 'storageVolume', 'stateVolume', 'secretsVolume', 'ipcVolume'] as $name) {
            $state->{$name} = 'baander-layout-' . strtolower($name);
        }
        $reflection = new \ReflectionClass(OperatorQualification::class);
        $qualification = $reflection->newInstanceWithoutConstructor();
        foreach (['root' => dirname(__DIR__, 3), 'state' => $state, 'commands' => $commands, 'resources' => $resources, 'configuration' => new OperatorConfiguration($environment, $commands->runtime->configuration)] as $name => $value) {
            $reflection->getProperty($name)->setValue($qualification, $value);
        }
        $provisioning = new OperatorProvisioning($qualification);
        try {
            $reflection->getProperty('topology')->setValue($qualification, new OperatorTopology($commands->client()->target, false, $environment));
            new \ReflectionMethod($provisioning, 'createManifest')->invoke($provisioning, [0, 1, 2, 3], '5432');
            if ($parseCredentials) {
                $credentials = DeploymentOperatorCredentials::fromFile($directory . '/work/credentials.json');
                self::assertSame($appHome . '/storage/conversions', $credentials->runtimeEnvironment->variables['CONVERT_STORAGE_PATH']);
            }
            $savedCredentials = AtomicFile::readJson($directory . '/work/credentials.json');
            $runtimeEnvironment = new DeploymentRuntimeEnvironment($savedCredentials['runtimeEnvironment']);
            self::assertSame($appHome . '/storage/conversions', $runtimeEnvironment->variables['CONVERT_STORAGE_PATH']);
            self::assertArrayNotHasKey('APP_HOME', $savedCredentials['runtimeEnvironment']);
            self::assertStringContainsString('APP_HOME=' . $appHome . "\n", file_get_contents($directory . '/work/runtime.env'));
            $manifest = DeploymentOperatorManifest::fromJson(file_get_contents($directory . '/work/manifest.json'), $appHome);
            self::assertSame($appHome, $manifest->appHome);
            new \ReflectionMethod($provisioning, 'prepareMediaAndApplication')->invoke($provisioning);
            $fake = AtomicFile::readJson($directory . '/state.json');
            $creates = array_values(array_filter($fake['calls'], static fn(array $call): bool => $call[0] === 'create'));
            self::assertCount(2, $creates);
            $media = $creates[0];
            $script = $media[array_search('-ec', $media, true) + 1];
            self::assertStringContainsString($appHome . '/storage/media', $script);
            self::assertStringContainsString($appHome . '/storage/conversions', $script);
            self::assertStringContainsString($appHome . '/var/transcode_state', $script);
            self::assertStringNotContainsString('%APP_HOME%', json_encode($fake['calls'], JSON_THROW_ON_ERROR));
            self::assertSame([], $resources->cleanup());
            self::assertSame([], AtomicFile::readJson($directory . '/state.json')['containers']);
        } finally {
            $resources->cleanup();
            unset($resources);
            $commands->close();
            Collector::remove($directory);
        }
    }

}
