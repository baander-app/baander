<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\LocalConfiguration;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\WorkflowError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/development-command-executor.php';

final class DevelopmentLocalConfigurationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-local-config-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/checkout', 0700);
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->directory);
    }

    public function testToolingSettingsShareDiscoveryWithoutValidatingLibraryDirectories(): void
    {
        $root = $this->directory . '/checkout';
        file_put_contents($root . '/dev.local.json', '{"libraries":[{"path":"/missing/baander.app"}],"tooling":{"budgets":{"backend":90}}}');
        $configuration = LocalConfiguration::load(new DevelopmentCommandExecutor(), $root, ['CI' => '0']);
        self::assertSame(90, $configuration['tooling']['budgets']['backend']);
        $this->expectException(WorkflowError::class);
        $this->libraries(new DevelopmentCommandExecutor(), $root);
    }

    public function testLibrariesAllowValidatedToolingAndRejectUnknownToolingKeys(): void
    {
        $root = $this->directory . '/checkout';
        file_put_contents($root . '/dev.local.json', '{"libraries":[],"tooling":{"budgets":{"cleanup":90}}}');
        self::assertSame([], $this->libraries(new DevelopmentCommandExecutor(), $root));
        file_put_contents($root . '/dev.local.json', '{"tooling":{"applicationRoot":"/opt/app"}}');
        $this->expectException(WorkflowError::class);
        LocalConfiguration::load(new DevelopmentCommandExecutor(), $root, ['CI' => '0']);
    }
    /** @return list<array{slug:string,name:string,type:string,source:string,target:string}>|null */
    private function libraries(DevelopmentCommandExecutor $runner, string $root): ?array
    {
        return LocalConfiguration::libraries($runner, $root, LocalConfiguration::load($runner, $root, ['CI' => '0']));
    }

    private function configuration(string $root, string $name = 'Movies'): void
    {
        file_put_contents($root . '/dev.local.json', json_encode(['libraries' => [['slug' => 'movies', 'name' => $name, 'type' => 'movie', 'path' => $this->directory . '/checkout']]], JSON_THROW_ON_ERROR));
    }

    public function testAbsentOmittedAndEmptyLibrariesRemainDistinct(): void
    {
        $root = $this->directory . '/checkout';
        self::assertNull($this->libraries(new DevelopmentCommandExecutor(), $root));
        file_put_contents($root . '/dev.local.json', '{}');
        self::assertNull($this->libraries(new DevelopmentCommandExecutor(), $root));
        file_put_contents($root . '/dev.local.json', '{"libraries":[]}');
        self::assertSame([], $this->libraries(new DevelopmentCommandExecutor(), $root));
    }

    public function testLinkedWorktreesUseActualPrimaryCheckoutAndLocalOverride(): void
    {
        foreach ([false, true] as $separateGitDirectory) {
            $root = $this->directory . ($separateGitDirectory ? '/separate' : '/checkout');
            $runner = new DevelopmentCommandExecutor();
            $runner->execute(['git', 'init', '-q', ...($separateGitDirectory ? ['--separate-git-dir=' . $this->directory . '/metadata'] : []), $root]);
            $runner->execute(['git', '-c', 'user.name=Tooling', '-c', 'user.email=tooling@baander.app', 'commit', '--allow-empty', '-qm', 'fixture'], cwd: $root);
            if ($separateGitDirectory) {
                $runner->execute(['git', 'config', 'core.worktree', $root], cwd: $root);
            }
            $linked = $root . '-linked';
            $runner->execute(['git', 'worktree', 'add', '--detach', $linked], cwd: $root);
            $this->configuration($root);
            self::assertSame('Movies', $this->libraries($runner, $linked)[0]['name']);
            $this->configuration($linked, 'Local Movies');
            self::assertSame('Local Movies', $this->libraries($runner, $linked)[0]['name']);
            file_put_contents($linked . '/dev.local.json', '{}');
            self::assertNull($this->libraries($runner, $linked));
        }
    }

    public function testUnrecordedSeparateGitDirectoryDoesNotBecomeConfigurationCheckout(): void
    {
        $runner = new DevelopmentCommandExecutor();
        $root = $this->directory . '/checkout';
        $metadata = $this->directory . '/metadata';
        $linked = $this->directory . '/linked';
        $runner->execute(['git', 'init', '-q', '--separate-git-dir=' . $metadata, $root]);
        $runner->execute(['git', '-c', 'user.name=Tooling', '-c', 'user.email=tooling@baander.app', 'commit', '--allow-empty', '-qm', 'fixture'], cwd: $root);
        $runner->execute(['git', 'worktree', 'add', '--detach', $linked], cwd: $root);
        $this->configuration($root);
        $this->configuration($metadata, 'Wrong checkout');
        self::assertNull($this->libraries($runner, $linked));
        $this->configuration($linked, 'Local Movies');
        self::assertSame('Local Movies', $this->libraries($runner, $linked)[0]['name']);
    }

    public function testOrdinaryCheckoutDoesNotReadParentConfiguration(): void
    {
        $runner = new DevelopmentCommandExecutor();
        $runner->execute(['git', 'init', '-q', $this->directory . '/checkout']);
        $this->configuration($this->directory);
        self::assertNull($this->libraries($runner, $this->directory . '/checkout'));
    }

    public function testDefinitionsNormalizeApplicationSlugsAndResolvePaths(): void
    {
        $root = $this->directory . '/checkout';
        file_put_contents($root . '/dev.local.json', json_encode(['libraries' => [['slug' => ' Movies ', 'name' => 'Movies', 'type' => 'movie', 'path' => $root . '/.']]], JSON_THROW_ON_ERROR));
        self::assertSame([['slug' => 'movies', 'name' => 'Movies', 'type' => 'movie', 'source' => $root, 'target' => '/media/movies']], $this->libraries(new DevelopmentCommandExecutor(), $root));
    }

    public function testConfiguredNamePreservesWhitespaceLikeTheApplication(): void
    {
        $root = $this->directory . '/checkout';
        $this->configuration($root, ' Movies ');
        self::assertSame(' Movies ', $this->libraries(new DevelopmentCommandExecutor(), $root)[0]['name']);
    }

    public function testMalformedAndInvalidConfigurationIsRejected(): void
    {
        $valid = ['slug' => 'movies', 'name' => 'Movies', 'type' => 'movie', 'path' => $this->directory . '/checkout'];
        $cases = ['{broken', '[]', '{"unknown":[]}', '{"libraries":{}}', '{"libraries":[[]]}', '{"tooling":[]}', '{"tooling":{"budgets":[]}}'];
        foreach ([['extra' => 'bad'], ['slug' => 'bad_slug'], ['name' => ' '], ['type' => 'movies'], ['path' => 'relative'], ['path' => '/nonexistent/baander.app'], ['type' => 1]] as $change) {
            $cases[] = json_encode(['libraries' => [array_replace($valid, $change)]], JSON_THROW_ON_ERROR);
        }
        $cases[] = json_encode(['libraries' => [$valid, $valid]], JSON_THROW_ON_ERROR);
        foreach ($cases as $json) {
            file_put_contents($this->directory . '/checkout/dev.local.json', $json);
            try {
                $this->libraries(new DevelopmentCommandExecutor(), $this->directory . '/checkout');
                self::fail('Invalid configuration was accepted: ' . $json);
            } catch (WorkflowError $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
