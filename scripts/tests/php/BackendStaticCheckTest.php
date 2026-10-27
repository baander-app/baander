<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Backend\StaticCheck;
use Baander\Tooling\Development\Docker;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Inventory\SafeTar;
use PHPUnit\Framework\TestCase;

final class BackendStaticCheckTest extends TestCase
{
    private string $root;
    private Runner $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-static-check-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->runner = new Runner();
    }

    protected function tearDown(): void
    {
        $this->runner->run(['rm', '-rf', '--', $this->root]);
    }

    private function write(string $path, string $contents): void
    {
        $destination = $this->root . '/' . $path;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        file_put_contents($destination, $contents);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $this->runner->run(['git', ...$arguments], cwd: $this->root);
    }

    public function testArchiveContainsCurrentCheckoutAndDependenciesWithoutHostSecrets(): void
    {
        $this->git(['init', '-b', 'master']);
        $this->git(['config', 'user.email', 'dev@baander.app']);
        $this->git(['config', 'user.name', 'Development fixture']);
        foreach (['src/Marker.php', 'config/services.yaml', '.env'] as $path) {
            $this->write($path, 'baked source');
        }
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'fixture']);
        foreach (['src/Marker.php', 'config/services.yaml'] as $path) {
            $this->write($path, 'current checkout');
        }
        foreach (['config/secrets/oauth/private.key', 'config/secrets/test/test.decrypt.private.php', '.env.local', '.env'] as $path) {
            $this->write($path, 'host private');
        }
        $this->write('vendor/autoload.php', 'current dependencies');
        $this->write('packages/baander-tooling/src/StaticCheckFixture.php', <<<'PHP'
            <?php
            namespace Baander\Tooling;
            final readonly class StaticCheckFixture
            {
                public const string MARKER = 'current tooling';
            }
            PHP);
        $this->write('scripts/static-check-fixture.php', <<<'PHP'
            <?php
            require __DIR__ . '/bootstrap.php';
            echo Baander\Tooling\StaticCheckFixture::MARKER;
            PHP);
        $this->runner->run(['cp', dirname(__DIR__, 2) . '/bootstrap.php', $this->root . '/scripts/bootstrap.php']);
        $this->runner->run(['cp', '-R', dirname(__DIR__, 2) . '/vendor', $this->root . '/scripts/vendor']);
        $this->write('relay/composer.json', file_get_contents(dirname(__DIR__, 3) . '/relay/composer.json'));
        $this->runner->run(['cp', '-R', dirname(__DIR__, 3) . '/relay/vendor', $this->root . '/relay/vendor']);
        $this->write('relay/tests/Support/StaticCheckFixture.php', <<<'PHP'
            <?php
            namespace Baander\RegistryTests;
            final readonly class StaticCheckFixture
            {
                public const string MARKER = 'current registry tooling';
            }
            PHP);
        $this->write('relay/static-check-fixture.php', <<<'PHP'
            <?php
            require __DIR__ . '/vendor/autoload.php';
            echo Baander\RegistryTests\StaticCheckFixture::MARKER;
            PHP);
        $this->write('composer.lock', '{"packages":[],"packages-dev":[]}');
        $this->write('vendor/composer/installed.json', '{"packages":[]}');
        $workspace = $this->root . '/.git/check';
        mkdir($workspace, 0700);
        [, $revision, $archive] = StaticCheck::prepareCheckout($this->runner, $this->root, $workspace);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);
        rename($this->root . '/packages', $workspace . '/original-packages');
        rename($this->root . '/relay', $workspace . '/original-relay');
        $extracted = $workspace . '/extracted';
        SafeTar::extract($archive, $extracted);
        self::assertSame('current checkout', file_get_contents($extracted . '/src/Marker.php'));
        self::assertSame('current checkout', file_get_contents($extracted . '/config/services.yaml'));
        self::assertSame('current dependencies', file_get_contents($extracted . '/vendor/autoload.php'));
        self::assertFileExists($extracted . '/scripts/vendor/autoload.php');
        self::assertSame('../../../packages/baander-tooling/', readlink($extracted . '/scripts/vendor/baander/tooling'));
        self::assertDirectoryDoesNotExist($this->root . '/packages');
        $tooling = $this->runner->run([PHP_BINARY, $extracted . '/scripts/static-check-fixture.php']);
        self::assertSame('current tooling', $tooling->stdout);
        self::assertDirectoryDoesNotExist($this->root . '/relay');
        self::assertFileExists($extracted . '/relay/vendor/autoload.php');
        $registry = $this->runner->run([PHP_BINARY, $extracted . '/relay/static-check-fixture.php']);
        self::assertSame('current registry tooling', $registry->stdout);
        self::assertSame('baked source', file_get_contents($extracted . '/.env'));
        self::assertFileDoesNotExist($extracted . '/.env.local');
        self::assertDirectoryDoesNotExist($extracted . '/config/secrets');
    }

    public function testDependencyVersionsAndBothReferencesMustMatchLock(): void
    {
        $package = ['name' => 'baander/fixture', 'version' => '1.0',
            'source' => ['reference' => 'source-current'], 'dist' => ['reference' => 'dist-current']];
        $this->write('composer.lock', json_encode(['packages' => [], 'packages-dev' => [$package]], JSON_THROW_ON_ERROR));
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR));
        StaticCheck::validateDependencies($this->root);
        foreach ([['version' => '0.9'], ['source' => ['reference' => 'stale']], ['dist' => ['reference' => 'stale']]] as $change) {
            $this->write('vendor/composer/installed.json', json_encode(['packages' => [array_replace($package, $change)]], JSON_THROW_ON_ERROR));
            try {
                StaticCheck::validateDependencies($this->root);
                self::fail('Expected a dependency mismatch.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('composer install', $error->getMessage());
            }
        }
        unlink($this->root . '/vendor/composer/installed.json');
        $this->expectException(WorkflowError::class);
        StaticCheck::validateDependencies($this->root);
    }

    public function testDependenciesAreRequiredBeforeRunningDocker(): void
    {
        $this->expectExceptionMessage('Install Composer');
        StaticCheck::prepareCheckout($this->runner, $this->root, $this->root);
    }

    public function testToolingDependenciesAreRequiredBeforeCreatingAnArchive(): void
    {
        $this->write('vendor/autoload.php', 'current dependencies');
        try {
            StaticCheck::prepareCheckout($this->runner, $this->root, $this->root);
            self::fail('Expected the tooling loader prerequisite.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('composer --working-dir=scripts install --no-dev', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/source');
        self::assertFileDoesNotExist($this->root . '/checkout.tar');
    }

    public function testRegistryProjectRequiresItsOwnLoaderBeforeCreatingAnArchive(): void
    {
        $this->write('vendor/autoload.php', '<?php');
        $this->write('scripts/vendor/autoload.php', '<?php');
        $this->write('relay/composer.json', '{"name":"baander/registry-test-tooling"}');
        try {
            StaticCheck::prepareCheckout($this->runner, $this->root, $this->root);
            self::fail('Expected the registry loader prerequisite.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('composer --working-dir=relay install --no-dev', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/source');
        self::assertFileDoesNotExist($this->root . '/checkout.tar');
    }

    public function testCheckoutWithoutARegistryProjectDoesNotInventRegistryDependencies(): void
    {
        $this->git(['init', '-b', 'master']);
        $this->write('vendor/autoload.php', '<?php');
        $this->write('scripts/vendor/autoload.php', '<?php');
        $this->write('composer.lock', '{"packages":[],"packages-dev":[]}');
        $this->write('vendor/composer/installed.json', '{"packages":[]}');
        $workspace = $this->root . '/.git/check';
        mkdir($workspace, 0700);
        [, , $archive] = StaticCheck::prepareCheckout($this->runner, $this->root, $workspace);
        $extracted = $workspace . '/extracted';
        SafeTar::extract($archive, $extracted);
        self::assertFileExists($extracted . '/vendor/autoload.php');
        self::assertFileExists($extracted . '/scripts/vendor/autoload.php');
        self::assertDirectoryDoesNotExist($extracted . '/relay');
    }

    public function testEntrypointHelpAndUsageErrorsDoNotReachDocker(): void
    {
        $entrypoint = dirname(__DIR__, 2) . '/check-backend-container.php';
        $help = $this->runner->run([PHP_BINARY, $entrypoint, '--help']);
        self::assertSame(0, $help->exitCode);
        self::assertStringContainsString('Usage: check-backend-container.php', $help->stdout);
        self::assertSame('', $help->stderr);
        foreach ([[], ['--image'], ['--unknown']] as $arguments) {
            $result = $this->runner->run([PHP_BINARY, $entrypoint, ...$arguments], check: false);
            self::assertSame(2, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertNotSame('', $result->stderr);
        }
    }

    private function dockerRunner(bool $failCopy = false): StaticCheckRecordingRunner
    {
        return new StaticCheckRecordingRunner($failCopy);
    }

    public function testCachedRuntimeGetsCurrentArchiveAndUnprivilegedCommandWithExitStatus(): void
    {
        $runner = $this->dockerRunner();
        $docker = new Docker($runner, ['executable' => '/usr/bin/docker', 'context' => 'remote']);
        self::assertSame(7, StaticCheck::runCheck($docker, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php', 'literal $x; space']));
        $create = $runner->calls[0]['arguments'];
        self::assertContains('cached-runtime', $create);
        self::assertSame('none', $create[array_search('--network', $create, true) + 1]);
        self::assertContains('APP_ENV=test', $create);
        self::assertSame(['cp', '/tmp/current.tar'], array_slice($runner->calls[2]['arguments'], 3, 2));
        self::assertStringEndsWith(':/tmp/checkout.tar', $runner->calls[2]['arguments'][5]);
        $provision = $runner->calls[3]['arguments'][array_key_last($runner->calls[3]['arguments'])];
        foreach (['rm -rf /var/www/html', 'tar -xf /tmp/checkout.tar', 'openssl genrsa', 'openssl rsa'] as $fragment) {
            self::assertStringContainsString($fragment, $provision);
        }
        $execute = $runner->calls[4];
        self::assertSame(['exec', '--user', 'www-data'], array_slice($execute['arguments'], 3, 3));
        self::assertSame(['php', 'current.php', 'literal $x; space'], array_slice($execute['arguments'], -3));
        self::assertFalse($execute['check']);
        self::assertTrue($execute['interactive']);
        self::assertNull($execute['timeout']);
        self::assertSame(['rm', '--force'], array_slice($runner->calls[5]['arguments'], 3, 2));
    }

    public function testContainerIsRemovedWhenProvisioningFails(): void
    {
        $runner = $this->dockerRunner(true);
        try {
            StaticCheck::runCheck(
                new Docker($runner, ['executable' => '/usr/bin/docker', 'context' => 'remote']),
                'cached-runtime',
                '/tmp/current.tar',
                ['php', 'current.php'],
            );
            self::fail('Expected provisioning failure.');
        } catch (WorkflowError $error) {
            self::assertSame('copy failed', $error->getMessage());
        }
        self::assertSame(['rm', '--force'], array_slice($runner->calls[array_key_last($runner->calls)]['arguments'], 3, 2));
    }

    public function testDockerContextAndCleanEnvironmentSurviveHostOverrides(): void
    {
        $oldHost = getenv('DOCKER_HOST');
        $oldEnv = getenv('APP_ENV');
        putenv('DOCKER_HOST=tcp://baander.app:2375');
        putenv('APP_ENV=prod');
        try {
            $result = new Docker($this->runner, ['executable' => __DIR__ . '/Fixtures/static-check-docker.php', 'context' => 'isolated'])->run(['info']);
            self::assertSame(['argv' => ['--context', 'isolated', 'info'], 'docker_host' => false, 'app_env' => false], json_decode($result->stdout, true));
        } finally {
            putenv($oldHost === false ? 'DOCKER_HOST' : 'DOCKER_HOST=' . $oldHost);
            putenv($oldEnv === false ? 'APP_ENV' : 'APP_ENV=' . $oldEnv);
        }
    }
}

final class StaticCheckRecordingRunner extends Runner
{
    /** @var list<array{arguments: non-empty-list<string>, check: bool, interactive: bool, timeout: ?float}> */
    public array $calls = [];

    public function __construct(private readonly bool $failCopy) {}

    public function run(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $this->calls[] = ['arguments' => $arguments, 'check' => $check, 'interactive' => $interactive, 'timeout' => $timeout];
        if ($this->failCopy && $arguments[3] === 'cp') {
            throw new WorkflowError('copy failed');
        }
        return new ProcessResult($interactive ? 7 : 0, null, false, false, '', '', 0);
    }
}
