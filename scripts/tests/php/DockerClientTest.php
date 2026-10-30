<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\DockerSettings;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\OperatorTransport;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DockerClientTest extends TestCase
{
    private string $root;
    /** @var array<string,string> */
    private array $environment;
    private EngineExecutor $executor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-docker-client-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        file_put_contents($this->root . '/endpoint', 'unix:///selected.sock');
        file_put_contents($this->root . '/id', 'selected-daemon');
        file_put_contents($this->root . '/docker', '#!' . PHP_BINARY . "\n" . <<<'CLI'
<?php
$args = array_slice($argv, 1);
file_put_contents(getenv('FAKE_ROOT') . '/calls', json_encode([$args, getenv('DOCKER_CONTEXT'), getenv('DOCKER_HOST'), getenv('DOCKER_CONFIG'), ['BUILDX_BUILDER' => getenv('BUILDX_BUILDER'), 'BUILDKIT_HOST' => getenv('BUILDKIT_HOST'), 'DOCKER_BUILDKIT' => getenv('DOCKER_BUILDKIT'), 'CUSTOM_BUILD_SETTING' => getenv('CUSTOM_BUILD_SETTING')]]) . "\n", FILE_APPEND);
if ($args[0] === 'context') {
    if ($args[1] === 'show') { echo 'selected'; exit; }
    $material = file_exists(getenv('FAKE_ROOT') . '/declared-tls') ? json_decode(file_get_contents(getenv('FAKE_ROOT') . '/declared-tls'), true) : [];
    echo json_encode([['Endpoints' => ['docker' => ['Host' => trim(file_get_contents(getenv('FAKE_ROOT') . '/endpoint')), 'SkipTLSVerify' => false]], 'TLSMaterial' => ['docker' => $material], 'Storage' => ['TLSPath' => getenv('FAKE_ROOT') . '/tls']]]); exit;
}
if (in_array('group', $args, true)) { preg_match('/^\d+ \(.*\) \S+ \d+ (\d+)/', file_get_contents('/proc/self/stat'), $match); echo getmypid() === (int) $match[1] ? 'contained' : 'shared'; exit; }
if (in_array('stall', $args, true)) { usleep(2000000); exit; }
if (in_array('info', $args, true)) { echo json_encode(['ID' => trim(file_get_contents(getenv('FAKE_ROOT') . '/id'))]); exit; }
if (in_array('compose', $args, true)) { exit(71); }
if (in_array('inspect', $args, true)) { echo 'sha256:' . str_repeat('a', 64); exit; }
echo json_encode($args);
CLI);
        chmod($this->root . '/docker', 0700);
        $this->environment = ['PATH' => '/usr/bin:/bin', 'FAKE_ROOT' => $this->root, 'DOCKER_CONTEXT' => 'ambient', 'DOCKER_HOST' => 'unix:///ambient.sock', 'DOCKER_CONFIG' => $this->root . '/auth'];
        $this->executor = new EngineExecutor(new ExecutionContext($this->environment));
    }

    protected function tearDown(): void
    {
        $this->executor->close();
        $remove = function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (array_diff(scandir($path), ['.', '..']) as $name) {
                    $remove($path . '/' . $name);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->root);
    }

    private function resolve(?string $host = null, ?string $context = 'selected'): Target
    {
        return new TargetResolver($this->executor, $this->environment)->resolve(new DockerSettings($this->root . '/docker', $host, $context), $this->root . '/snapshot');
    }

    public function testCapturedContextSurvivesRetargetingForSyncAsyncAndRecovery(): void
    {
        $target = $this->resolve();
        file_put_contents($this->root . '/endpoint', 'unix:///other.sock');
        $client = new Client($this->executor, Target::fromArray($target->toArray()), $this->environment);
        self::assertSame('selected-daemon', $client->validateIdentity()['ID']);
        self::assertStringContainsString('selected.sock', $client->run(['ps'])->stdout);
        self::assertStringContainsString('selected.sock', $this->executor->wait($client->start(['ps']))->stdout);
        $calls = array_map(fn($line) => json_decode($line, true), file($this->root . '/calls', FILE_IGNORE_NEW_LINES));
        foreach (array_slice($calls, 1) as [$args, $context, $host, $config]) {
            self::assertFalse($context);
            self::assertFalse($host);
            self::assertSame($this->root . '/auth', $config);
            self::assertNotContains('--context', $args);
        }
    }

    public function testNestedInvocationUsesCapturedTargetWithoutResolvingAmbientContext(): void
    {
        $target = $this->resolve();
        $file = $this->root . '/target.json';
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($file, $target->toArray());
        file_put_contents($this->root . '/calls', '');
        file_put_contents($this->root . '/endpoint', 'unix:///other.sock');
        $environment = $this->environment + ['BAANDER_TOOLING_TARGET_FILE' => $file];
        $resolved = new TargetResolver($this->executor, $environment)->resolve(new DockerSettings('/missing/ambient-docker', context: 'changed'), $this->root . '/unused-snapshot');
        self::assertSame($target->toArray(), $resolved->toArray());
        self::assertDirectoryDoesNotExist($this->root . '/unused-snapshot');
        $calls = file($this->root . '/calls', FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $calls);
        self::assertSame(['--host', 'unix:///selected.sock', 'info', '--format', '{{json .}}'], json_decode($calls[0], true, flags: JSON_THROW_ON_ERROR)[0]);
    }

    /** @return iterable<string,array{list<string>,list<string>,bool}> */
    public static function pinnedBuilds(): iterable
    {
        yield 'plain build' => [['build'], ['build', '--builder', 'default'], false];
        yield 'asynchronous buildx' => [['buildx', 'build', '--load'], ['buildx', 'build', '--builder', 'default', '--load'], true];
        yield 'explicit default' => [['build', '--builder', 'default'], ['build', '--builder', 'default'], false];
        yield 'explicit buildx default' => [['buildx', 'build', '--builder=default'], ['buildx', 'build', '--builder=default'], false];
        yield 'buildx root selector' => [['buildx', '--builder', 'default', '--debug', 'build'], ['buildx', '--builder', 'default', '--debug', 'build'], false];
        yield 'image build alias' => [['image', 'build'], ['image', 'build', '--builder', 'default'], false];
        yield 'builder build alias' => [['builder', 'build'], ['builder', 'build', '--builder', 'default'], false];
    }

    /** @param list<string> $prefix
     * @param list<string> $expected
     */
    #[DataProvider('pinnedBuilds')]
    public function testBuildsUseFrozenDaemonAndCommandLocalBuilderEnvironment(array $prefix, array $expected, bool $asynchronous): void
    {
        $target = $this->resolve();
        $captured = $this->environment + ['BUILDX_BUILDER' => 'captured-foreign', 'BUILDKIT_HOST' => 'tcp://captured.baander.app:1234', 'DOCKER_BUILDKIT' => '0'];
        $overrides = ['BUILDX_BUILDER' => 'override-foreign', 'BUILDKIT_HOST' => 'tcp://override.baander.app:1234', 'DOCKER_BUILDKIT' => 'false', 'CUSTOM_BUILD_SETTING' => 'preserved'];
        $client = new Client($this->executor, $target, $captured);
        $options = ['--tag', 'baander-build:test', '--cache-from', 'type=registry,ref=registry.baander.app/cache',
            '--cache-to', 'type=local,dest=/tmp/baander-cache', '--build-arg', 'VALUE=--builder=preserved', '--', './context'];
        if ($asynchronous) {
            $this->executor->wait($client->start([...$prefix, ...$options], env: $overrides));
        } else {
            $client->run([...$prefix, ...$options], env: $overrides);
        }
        $calls = array_map(static fn(string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root . '/calls', FILE_IGNORE_NEW_LINES));
        [$arguments, , , $config, $environment] = $calls[array_key_last($calls)];
        self::assertSame(['--host', 'unix:///selected.sock', ...$expected, ...$options], $arguments);
        self::assertSame($this->root . '/auth', $config);
        self::assertFalse($environment['BUILDX_BUILDER']);
        self::assertFalse($environment['BUILDKIT_HOST']);
        self::assertSame('1', $environment['DOCKER_BUILDKIT']);
        self::assertSame('preserved', $environment['CUSTOM_BUILD_SETTING']);
        self::assertSame('captured-foreign', $captured['BUILDX_BUILDER']);
        self::assertSame('override-foreign', $overrides['BUILDX_BUILDER']);
        $client->run(['version'], env: $overrides);
        $calls = file($this->root . '/calls', FILE_IGNORE_NEW_LINES);
        $nonBuild = json_decode($calls[array_key_last($calls)], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($overrides, $nonBuild[4]);
        $client->run(['version']);
        $calls = file($this->root . '/calls', FILE_IGNORE_NEW_LINES);
        $capturedAgain = json_decode($calls[array_key_last($calls)], true, flags: JSON_THROW_ON_ERROR)[4];
        self::assertSame('captured-foreign', $capturedAgain['BUILDX_BUILDER']);
        self::assertSame('tcp://captured.baander.app:1234', $capturedAgain['BUILDKIT_HOST']);
        self::assertSame('0', $capturedAgain['DOCKER_BUILDKIT']);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function conflictingBuildSelectors(): iterable
    {
        yield 'plain foreign' => [['build', '--builder', 'foreign']];
        yield 'buildx foreign' => [['buildx', 'build', '--builder=foreign']];
        yield 'duplicate default' => [['build', '--builder', 'default', '--builder=default']];
        yield 'missing builder' => [['buildx', 'build', '--builder']];
        yield 'foreign root selector' => [['buildx', '--builder', 'foreign', 'build']];
        yield 'duplicate across root and command' => [['buildx', '--builder=default', 'build', '--builder', 'default']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('conflictingBuildSelectors')]
    public function testBuildSelectorConflictsFailBeforeExecutingDocker(array $arguments): void
    {
        $client = new Client($this->executor, $this->resolve(), $this->environment);
        file_put_contents($this->root . '/calls', '');
        try {
            $client->run($arguments);
            self::fail('An ambiguous or foreign builder was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('builder', $error->getMessage());
        }
        self::assertSame('', file_get_contents($this->root . '/calls'));
    }

    public function testDockerDoesNotRequireComposeAndDaemonMismatchRetainsSnapshot(): void
    {
        $target = $this->resolve();
        file_put_contents($this->root . '/id', 'other-daemon');
        $client = new Client($this->executor, $target, $this->environment);
        try {
            $client->validateIdentity();
            self::fail('Identity mismatch accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('identity', $error->getMessage());
        }
        self::assertDirectoryExists($target->snapshotDirectory);
        $this->expectException(\RuntimeException::class);
        $client->requireCompose();
    }

    public function testExplicitHostAndNestedMappingKeepDaemonOwnership(): void
    {
        $target = $this->resolve('unix:///chosen.sock', null);
        self::assertSame('unix:///chosen.sock', $target->endpoint);
        $nested = (new OperatorTransport('/host/docker.sock', '/run/docker.sock'))->target($target, '/usr/bin/docker');
        self::assertSame('unix:///run/docker.sock', $nested->endpoint);
        self::assertSame($target->daemonId, $nested->daemonId);
        $nested = OperatorTransport::fromArray((new OperatorTransport('/host/docker.sock', '/run/docker.sock'))->toArray())->target($target, $this->root . '/docker');
        $client = new Client($this->executor, Target::fromArray($nested->toArray()), $this->environment);
        self::assertSame('selected-daemon', $client->validateIdentity()['ID']);
        file_put_contents($this->root . '/id', 'other-daemon');
        $this->expectExceptionMessage('identity changed');
        $client->validateIdentity();
    }

    public function testTlsCopiesArePrivateAndChangedSnapshotIsRejected(): void
    {
        mkdir($this->root . '/tls/docker', 0700, true);
        foreach (['ca.pem', 'cert.pem', 'key.pem'] as $name) {
            file_put_contents($this->root . '/tls/docker/' . $name, $name);
        }
        $target = $this->resolve();
        file_put_contents($this->root . '/tls/docker/key.pem', 'source changed');
        (new Client($this->executor, $target, $this->environment))->run(['ps']);
        self::assertNotEmpty($target->files);
        foreach ($target->files as $path => $hash) {
            self::assertSame(0600, fileperms($path) & 0777);
        }
        self::assertSame(0700, fileperms($target->snapshotDirectory) & 0777);
        $path = array_key_first($target->files);
        file_put_contents($path, 'changed');
        $this->expectExceptionMessage('snapshot');
        (new Client($this->executor, $target, $this->environment))->run(['ps']);
    }

    public function testRemoteEndpointNeedsExplicitPublishedPortTopology(): void
    {
        $client = new Client($this->executor, $this->resolve('tcp://remote.baander.app:2376', null), $this->environment);
        $this->expectExceptionMessage('published-port');
        $client->assertLocalPublishedPorts();
    }

    public function testRemovedSnapshotNeverFallsBackToAmbientSelection(): void
    {
        $target = $this->resolve();
        rmdir($target->snapshotDirectory);
        $this->expectExceptionMessage('snapshot is missing');
        (new Client($this->executor, $target, $this->environment))->run(['ps']);
    }

    public function testSavedTargetCannotInjectAnotherHostThroughTransportArguments(): void
    {
        $saved = $this->resolve()->toArray();
        $saved['transportArguments'] = ['--host', 'unix:///other.sock'];
        $this->expectException(\InvalidArgumentException::class);
        Target::fromArray($saved);
    }

    public function testBuiltInDefaultAndAmbientPrecedenceResolveExplicitEndpoints(): void
    {
        $target = $this->resolve(null, 'default');
        self::assertSame('unix:///selected.sock', $target->endpoint);
        self::assertSame('default', $target->originalContext);
        $ambient = (new TargetResolver($this->executor, $this->environment))->resolve(new DockerSettings($this->root . '/docker'), $this->root . '/ambient-snapshot');
        self::assertSame('ambient', $ambient->originalContext);
        self::assertSame('unix:///selected.sock', $ambient->endpoint);
    }

    public function testSelectedImageHasImmutableIdentityAndSavedRoleMetadata(): void
    {
        $client = new Client($this->executor, $this->resolve(), $this->environment);
        $selection = new \Baander\Tooling\Configuration\ImageSelection('registry.baander.app/browser:1', ['browser'], '1.55.0');
        $image = $client->resolveImage($selection);
        self::assertSame('sha256:' . str_repeat('a', 64), $image->id);
        self::assertSame('selected-daemon', $image->daemonId);
        self::assertSame('1.55.0', $image->toArray()['playwrightVersion']);
    }

    public function testTlsWithoutClientCertificateDoesNotAcquireAmbientCredentials(): void
    {
        mkdir($this->root . '/tls/docker', 0700, true);
        file_put_contents($this->root . '/tls/docker/ca.pem', 'server CA');
        $target = $this->resolve();
        self::assertCount(1, $target->files);
        self::assertSame(['--tlsverify', '--tlscacert', $target->snapshotDirectory . '/ca.pem', '--tlscert', '', '--tlskey', ''], $target->transportArguments);
    }

    public function testCommandsOwnTheirProcessGroupAndUseResolvedCommandBudget(): void
    {
        $target = $this->resolve();
        $client = new Client($this->executor, $target, $this->environment);
        self::assertSame('contained', $client->run(['group'])->stdout);
        $boundedExecutor = new EngineExecutor(ExecutionContext::bounded(0.05, $this->environment));
        $bounded = new Client($boundedExecutor, $target, $this->environment);
        try {
            $bounded->run(['stall']);
            self::fail('Docker command budget was ignored.');
        } catch (\Baander\Tooling\Process\CommandFailure $error) {
            self::assertSame(124, $error->getCode());
        } finally {
            $boundedExecutor->close();
        }
    }

    public function testMissingDeclaredTlsMaterialFailsBeforeConnecting(): void
    {
        file_put_contents($this->root . '/declared-tls', json_encode(['ca.pem', 'cert.pem', 'key.pem']));
        try {
            $this->resolve();
            self::fail('A broken TLS context connected without its credentials.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('TLS', $error->getMessage());
        }
        self::assertCount(1, file($this->root . '/calls'));
    }

    public function testComposeAllowsExpectedExitWithoutWaivingTimeout(): void
    {
        $client = new Client($this->executor, $this->resolve(), $this->environment);
        self::assertSame(71, $client->compose('qualification', '/tmp/compose.json', ['ps'], check: false)->exitCode);
        $calls = file($this->root . '/calls', FILE_IGNORE_NEW_LINES);
        $arguments = json_decode(end($calls), true, flags: JSON_THROW_ON_ERROR)[0];
        self::assertSame(['--host', 'unix:///selected.sock', 'compose', '--project-name', 'qualification', '--file', '/tmp/compose.json', 'ps'], $arguments);
        try {
            $client->compose('qualification', '/tmp/compose.json', ['stall'], timeout: 0.05, check: false);
            self::fail('Compose timeout was accepted.');
        } catch (\Baander\Tooling\Process\CommandFailure $error) {
            self::assertSame(124, $error->getCode());
        }
    }
}
