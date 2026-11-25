<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use Baander\Configuration\ToolingConfiguration;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\DevQualification\Acceptance\BrowserCheckpoint;
use Baander\DevQualification\Acceptance\Support;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentBrowserCleanupTest extends TestCase
{
    private EngineExecutor $executor;
    protected function setUp(): void
    {
        $this->executor = new EngineExecutor(new ExecutionContext(getenv()));
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
            symlink(dirname(__DIR__, 3) . '/packages/baander-dev-qualification/tests/Fixtures/browser-cleanup-docker.php', $root . '/docker');
            $result = $this->executor->run(new ProcessSpec([
                'bash',
                dirname(__DIR__, 3) . '/scripts/test-two-command-browser-checkpoint.sh',
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
            ], processGroup: true), new CompletionPolicy(null));
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
            $configuration = ToolingConfiguration::resolve(
                ['images' => ['browser' => ['reference' => 'registry.baander.app/browser:approved', 'playwrightVersion' => '1.60.0']]],
                $environment,
            );
            $executor = new EngineExecutor(ExecutionContext::bounded(10, $environment));
            $target = new Target(dirname(__DIR__, 3) . '/packages/baander-dev-qualification/tests/Fixtures/browser-checkpoint-docker.php', 'unix:///selected.sock', 'selected-daemon', null, null);
            $checkpoint = new BrowserCheckpoint(new Client($executor, $target, $environment), $configuration, $environment, $root);
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
            $configuration = ToolingConfiguration::resolve(environment: $environment);
            $executor = new EngineExecutor(ExecutionContext::bounded(5, $environment));
            $target = new Target(dirname(__DIR__, 3) . '/packages/baander-dev-qualification/tests/Fixtures/browser-checkpoint-docker.php', 'unix:///selected.sock', 'selected-daemon', null, null);
            try {
                new BrowserCheckpoint(new Client($executor, $target, $environment), $configuration, $environment, $root)->run('authenticated', $root, 'https://browser.baander.app');
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
