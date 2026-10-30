<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Acceptance\Support;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentBrowserCleanupTest extends TestCase
{
    private \Baander\Tooling\Process\EngineExecutor $executor;
    protected function setUp(): void
    {
        $this->executor = new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv()));
    }

    protected function tearDown(): void
    {
        $this->executor->close();
    }

    /** @return iterable<string,array{string,int,bool}> */
    public static function scenarios(): iterable
    {
        yield 'verified removal' => ['removed', 0, false];
        yield 'never created' => ['never-created', 0, false];
        yield 'daemon failure cannot prove absence' => ['daemon-failure', 1, true];
        yield 'surviving container' => ['survivor', 1, true];
        yield 'missing evidence after removal' => ['copy-failure', 1, false];
    }

    #[DataProvider('scenarios')]
    public function testCleanupRequiresVerifiedRemovalAndEvidence(string $scenario, int $status, bool $retained): void
    {
        $root = Support::temporary('baander-browser-cleanup-test-');
        try {
            mkdir($root . '/artifacts');
            $marker = $root . '/artifacts/browser-container';
            file_put_contents($root . '/artifacts/browser-budget.json', json_encode(['cleanup' => 5], JSON_THROW_ON_ERROR));
            $executable = $root . '/docker';
            file_put_contents($marker, json_encode([
                'version' => 1, 'token' => str_repeat('a', 32),
                'target' => ['version' => 1, 'executable' => $executable, 'endpoint' => 'unix:///captured.sock', 'daemonId' => 'test-daemon', 'originalContext' => 'captured', 'snapshotDirectory' => null, 'transportArguments' => [], 'files' => []],
                'resources' => [['kind' => 'container', 'name' => 'baander-browser-cleanup-test', 'id' => str_repeat('b', 64)]],
            ], JSON_THROW_ON_ERROR));
            symlink(__DIR__ . '/Fixtures/browser-cleanup-docker.php', $root . '/docker');
            $result = $this->executor->run(new \Baander\Tooling\Process\ProcessSpec([
                'bash',
                dirname(__DIR__, 2) . '/test-two-command-browser-checkpoint.sh',
                'cleanup',
                $root . '/artifacts',
                '',
            ], environment: [
                'PATH' => $root . PATH_SEPARATOR . getenv('PATH'),
                'CLEANUP_TEST_SCENARIO' => $scenario,
                'CLEANUP_TEST_REMOVED' => $root . '/removed',
                'BAANDER_BROWSER_IMAGE' => 'invalid image must not be revalidated during recovery',
                'DOCKER_HOST' => 'unix:///wrong.sock',
                'DOCKER_CONTEXT' => 'deleted-context',
            ], processGroup: true), new \Baander\Tooling\Process\CompletionPolicy(null));
            self::assertSame($status, $result->exitCode, $result->stderr);
            self::assertSame($retained, file_exists($marker));
        } finally {
            Support::removeTree($root);
        }
    }
    public function testSelectedImageAndDaemonApplyToEveryBrowserCommand(): void
    {
        $root = Support::temporary('baander-browser-selection-');
        $executor = null;
        try {
            mkdir($root . '/ui/web/node_modules/playwright-core', 0700, true);
            mkdir($root . '/ui/web/tests/e2e/two-command', 0700, true);
            mkdir($root . '/artifacts');
            file_put_contents($root . '/ui/web/node_modules/playwright-core/package.json', '{"version":"1.60.0"}');
            file_put_contents($root . '/ui/web/tests/e2e/two-command/browser.mjs', '');
            file_put_contents($root . '/artifacts/browser.json', '{}');
            $environment = array_replace(getenv(), ['BROWSER_TEST_COMMANDS' => $root . '/commands', 'BAANDER_TEST_BROWSER_ENGINE' => '', 'DOCKER_HOST' => 'unix:///wrong.sock']);
            $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::resolve(
                ['images' => ['browser' => ['reference' => 'registry.baander.app/browser:approved', 'playwrightVersion' => '1.60.0']]],
                $environment,
            );
            $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(10, $environment));
            $target = new \Baander\Tooling\Docker\Target(__DIR__ . '/Fixtures/browser-checkpoint-docker.php', 'unix:///selected.sock', 'selected-daemon', null, null);
            $checkpoint = new \Baander\Tooling\Development\Acceptance\BrowserCheckpoint(new \Baander\Tooling\Docker\Client($executor, $target, $environment), $configuration, $environment, $root);
            ob_start();
            try {
                self::assertSame(0, $checkpoint->run('authenticated', $root . '/artifacts', 'https://browser.baander.app'));
                self::assertSame(0, $checkpoint->run('attached', $root . '/artifacts', 'https://browser.baander.app'));
            } finally {
                ob_end_clean();
            }
            $commands = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root . '/commands', FILE_IGNORE_NEW_LINES));
            $create = array_values(array_filter($commands, static fn(array $arguments): bool => $arguments[2] === 'create'))[0];
            self::assertContains('sha256:' . str_repeat('c', 64), $create);
            self::assertContains('BAANDER_TEST_BROWSER_ENGINE=chromium', $create);
            self::assertContains(['--host', 'unix:///selected.sock', 'pull', 'registry.baander.app/browser:approved'], $commands);
            foreach ($commands as $command) {
                self::assertSame(['--host', 'unix:///selected.sock'], array_slice($command, 0, 2));
            }
            $receipt = json_decode(file_get_contents($root . '/artifacts/browser-container'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('selected-daemon', $receipt['target']['daemonId']);
            self::assertSame(str_repeat('b', 64), $receipt['resources'][0]['id']);
        } finally {
            $executor?->close();
            Support::removeTree($root);
        }
    }

    public function testPlaywrightMismatchFailsBeforeDockerMutation(): void
    {
        $root = Support::temporary('baander-browser-version-');
        $executor = null;
        try {
            mkdir($root . '/ui/web/node_modules/playwright-core', 0700, true);
            file_put_contents($root . '/ui/web/node_modules/playwright-core/package.json', '{"version":"1.59.0"}');
            $environment = array_replace(getenv(), ['BROWSER_TEST_COMMANDS' => $root . '/commands']);
            $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::resolve(environment: $environment);
            $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded(5, $environment));
            $target = new \Baander\Tooling\Docker\Target(__DIR__ . '/Fixtures/browser-checkpoint-docker.php', 'unix:///selected.sock', 'selected-daemon', null, null);
            try {
                new \Baander\Tooling\Development\Acceptance\BrowserCheckpoint(new \Baander\Tooling\Docker\Client($executor, $target, $environment), $configuration, $environment, $root)->run('authenticated', $root, 'https://browser.baander.app');
                self::fail('Expected the installed Playwright mismatch to fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('installed package is 1.59.0', $error->getMessage());
            }
            self::assertFileDoesNotExist($root . '/commands');
            self::assertFileDoesNotExist($root . '/browser-container');
        } finally {
            $executor?->close();
            Support::removeTree($root);
        }
    }

}
