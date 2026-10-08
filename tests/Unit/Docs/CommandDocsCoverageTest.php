<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use App\Tests\Support\Console\CommandDocsLocator;
use App\Tests\Support\Console\ProjectCommands;
use PHPUnit\Framework\TestCase;

/**
 * Every console command Baander defines has an operator docs page and a row in
 * the commands index, and every page and index row names a command that exists
 * (R10).
 *
 * A command defined under `src/` needs its own page or an index row linking to a
 * section of a family page (see {@see CommandDocsLocator}). Framework commands,
 * such as Symfony's `messenger:failed:*`, are not under `src/` and are not
 * checked; the index may mention them as plain code.
 */
final class CommandDocsCoverageTest extends TestCase
{
    private const string COMMANDS_DIRECTORY = 'docs-book/part-1-operator-guide/commands';

    private string $fixtureDirectory = '';

    protected function tearDown(): void
    {
        if ($this->fixtureDirectory !== '') {
            array_map(unlink(...), glob($this->fixtureDirectory . '/*.md') ?: []);
            rmdir($this->fixtureDirectory);
        }
    }

    public function testEveryProjectCommandHasAPageAndAnIndexRowAndEveryPageHasACommand(): void
    {
        $root = dirname(__DIR__, 3);
        $commands = array_keys(ProjectCommands::inDirectory($root . '/src'));

        $problems = $this->problems($commands, $root . '/' . self::COMMANDS_DIRECTORY);

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testACommandWithoutAPageFailsWithItsName(): void
    {
        $directory = $this->fixture([
            'README.md' => "| [app:fixture:documented](app-fixture-documented.md) | Documented |\n"
                . "| [app:fixture:undocumented](app-fixture-undocumented.md) | Page missing |\n",
            'app-fixture-documented.md' => "# app:fixture:documented\n",
        ]);

        self::assertSame(
            ['app:fixture:undocumented has no operator docs page (expected app-fixture-undocumented.md or an index row linking to a family page section).'],
            $this->problems(['app:fixture:documented', 'app:fixture:undocumented'], $directory),
        );
    }

    public function testACommandMissingFromTheIndexFailsWithItsName(): void
    {
        $directory = $this->fixture([
            'README.md' => "| [app:fixture:listed](app-fixture-listed.md) | Listed |\n",
            'app-fixture-listed.md' => "# app:fixture:listed\n",
            'app-fixture-unlisted.md' => "# app:fixture:unlisted\n",
        ]);

        self::assertSame(
            ['app:fixture:unlisted has no row in the commands index (README.md).'],
            $this->problems(['app:fixture:listed', 'app:fixture:unlisted'], $directory),
        );
    }

    public function testAPageOrIndexRowForARemovedCommandFails(): void
    {
        $directory = $this->fixture([
            'README.md' => "| [app:fixture:current](app-fixture-current.md) | Current |\n"
                . "| [app:fixture:removed](app-fixture-removed.md) | Removed |\n"
                . "| [app:family:get](app-family.md#appfamilyget) | Family section |\n",
            'app-fixture-current.md' => "# app:fixture:current\n",
            'app-fixture-removed.md' => "# app:fixture:removed\n",
            'app-family.md' => "# Family\n\n## app:family:get\n",
        ]);

        self::assertSame(
            [
                'The commands index (README.md) lists app:fixture:removed, which is not a command under src/.',
                'app-fixture-removed.md documents no existing command; remove or rename it.',
            ],
            $this->problems(['app:family:get', 'app:fixture:current'], $directory),
        );
    }

    /**
     * @param list<string> $commands
     *
     * @return list<string>
     */
    private function problems(array $commands, string $commandsDirectory): array
    {
        $locator = new CommandDocsLocator($commandsDirectory);
        $index = (string) file_get_contents($commandsDirectory . '/README.md');
        preg_match_all('/\[([a-z0-9-]+(?::[a-z0-9-]+)+)\]\([^)\s]+\.md(?:#[^)\s]*)?\)/', $index, $links);
        $indexed = array_unique($links[1]);

        $problems = [];
        $documentedPages = [];
        foreach ($commands as $command) {
            $page = $locator->pageFor($command);
            if ($page === null) {
                $problems[] = sprintf(
                    '%s has no operator docs page (expected %s.md or an index row linking to a family page section).',
                    $command,
                    str_replace(':', '-', $command),
                );
            } else {
                $documentedPages[explode('#', $page)[0]] = true;
            }
            if (!in_array($command, $indexed, true)) {
                $problems[] = sprintf('%s has no row in the commands index (README.md).', $command);
            }
        }

        foreach ($indexed as $listed) {
            if (!in_array($listed, $commands, true)) {
                $problems[] = sprintf('The commands index (README.md) lists %s, which is not a command under src/.', $listed);
            }
        }

        $pages = array_map(basename(...), glob($commandsDirectory . '/*.md') ?: []);
        sort($pages);
        foreach ($pages as $page) {
            if ($page !== 'README.md' && !isset($documentedPages[$page])) {
                $problems[] = sprintf('%s documents no existing command; remove or rename it.', $page);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, string> $files file name => contents
     */
    private function fixture(array $files): string
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/baander-command-docs-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDirectory);
        foreach ($files as $name => $contents) {
            file_put_contents($this->fixtureDirectory . '/' . $name, $contents);
        }

        return $this->fixtureDirectory;
    }
}
