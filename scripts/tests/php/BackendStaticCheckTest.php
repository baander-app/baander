<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Backend\StaticCheck;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
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

    /** @return array{\Baander\Tooling\Docker\Client,\Baander\Tooling\Resources\ResourceLedger} */
    private function resourceClient(): array
    {
        $environment = array_replace(getenv(), ['RESOURCE_TEST_STATE' => $this->root]);
        $client = new \Baander\Tooling\Docker\Client(
            new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(20, $environment)),
            new \Baander\Tooling\Docker\Target(__DIR__ . '/Fixtures/static-check-resource-docker.php', 'unix:///baander.app/docker.sock', 'owned-daemon', null, null),
            $environment,
        );
        return [$client, new \Baander\Tooling\Resources\ResourceLedger($this->root . '/receipt.json', $client, $environment)];
    }

    public function testCachedRuntimeGetsCurrentArchiveAndUnprivilegedCommandWithExitStatus(): void
    {
        [$client, $ledger] = $this->resourceClient();
        self::assertSame(7, StaticCheck::runCheck($client, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php', 'literal $x; space'], \Baander\Tooling\Configuration\ApplicationLayout::load(), $ledger, 10));
        self::assertFileDoesNotExist($this->root . '/resource.json');
        self::assertFileDoesNotExist($this->root . '/receipt.json');
        $calls = array_map(static fn(string $line): array => json_decode($line, true), file($this->root . '/calls.jsonl', FILE_IGNORE_NEW_LINES));
        $create = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'create'))[0];
        self::assertContains('cached-runtime', $create);
        self::assertSame('none', $create[array_search('--network', $create, true) + 1]);
        self::assertContains('APP_ENV=test', $create);
        $copy = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'cp'))[0];
        self::assertSame('/tmp/current.tar', $copy[1]);
        self::assertStringEndsWith(':/tmp/checkout.tar', $copy[2]);
        $exec = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'exec'));
        self::assertStringContainsString('tar -xf /tmp/checkout.tar', end($exec[0]));
        self::assertSame(['php', 'current.php', 'literal $x; space'], array_slice($exec[1], -3));
    }

    public function testContainerIsRemovedWhenProvisioningFails(): void
    {
        [$client, $ledger] = $this->resourceClient();
        touch($this->root . '/fail-copy');
        try {
            StaticCheck::runCheck($client, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php'], \Baander\Tooling\Configuration\ApplicationLayout::load(), $ledger, 10);
            self::fail('Expected provisioning failure.');
        } catch (\RuntimeException $error) {
            self::assertSame(19, $error->getCode());
            self::assertStringContainsString('copy failed', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/resource.json');
        self::assertFileDoesNotExist($this->root . '/receipt.json');
    }

    public function testImageLayoutMismatchIsRejectedBeforeCreatingResource(): void
    {
        [$client, $ledger] = $this->resourceClient();
        touch($this->root . '/wrong-layout');
        try {
            StaticCheck::runCheck($client, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php'], \Baander\Tooling\Configuration\ApplicationLayout::load(), $ledger, 10);
            self::fail('Expected image layout rejection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('layout', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/receipt.json');
        $calls = file($this->root . '/calls.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertSame([], array_filter($calls, static fn(string $line): bool => str_starts_with($line, '["create"')));
    }

    public function testSuccessfulCheckFailsOnRemovalAndPrimaryStatusIsPreserved(): void
    {
        [$client, $ledger] = $this->resourceClient();
        touch($this->root . '/fail-remove');
        touch($this->root . '/check-success');
        try {
            StaticCheck::runCheck($client, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php'], \Baander\Tooling\Configuration\ApplicationLayout::load(), $ledger, 10);
            self::fail('Expected cleanup failure.');
        } catch (\RuntimeException $error) {
            self::assertSame(1, $error->getCode());
            self::assertStringContainsString('cleanup', $error->getMessage());
        }
        self::assertFileExists($this->root . '/resource.json');
        self::assertFileExists($this->root . '/receipt.json');
        unlink($this->root . '/fail-remove');
        self::assertTrue($ledger->cleanup(10)->successful());
        self::assertFileDoesNotExist($this->root . '/resource.json');
        self::assertFileDoesNotExist($this->root . '/receipt.json');
        unlink($this->root . '/check-success');
        touch($this->root . '/fail-remove');
        try {
            StaticCheck::runCheck($client, 'cached-runtime', '/tmp/current.tar', ['php', 'current.php'], \Baander\Tooling\Configuration\ApplicationLayout::load(), $ledger, 10);
            self::fail('Expected cleanup failure alongside failed check.');
        } catch (\RuntimeException $error) {
            self::assertSame(7, $error->getCode());
            self::assertStringContainsString('cleanup', $error->getMessage());
        }
    }
}
