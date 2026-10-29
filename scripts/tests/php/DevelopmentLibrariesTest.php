<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Libraries;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class DevelopmentLibrariesTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function instance(): array
    {
        return [
            'docker' => DevelopmentCommandExecutor::target('fake', 'owned'),
            'project' => 'libraries-test',
            'compose_file' => '/disposable/compose.json',
            'libraries' => [[
                'slug' => 'music', 'name' => ' Music ', 'type' => 'music',
                'source' => '/host/music', 'target' => '/media/music',
            ]],
        ];
    }

    /** @return array<string,string> */
    private static function existing(): array
    {
        return ['slug' => 'music', 'name' => ' Music ', 'type' => 'music', 'path' => '/media/music', 'filesystemType' => 'local'];
    }

    public function testMissingLibraryIsCreatedOnceAndMatchingRestartDoesNothing(): void
    {
        $commands = [];
        $responses = ['[]', '{}', json_encode([self::existing()], JSON_THROW_ON_ERROR)];
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::exactly(3))->method('run')->willReturnCallback(static function (\Baander\Tooling\Process\ProcessSpec $command) use (&$commands, &$responses): ProcessResult {
            $arguments = $command->argv;
            $commands[] = $arguments;
            return new ProcessResult(0, null, false, false, array_shift($responses), '', 0);
        });
        $libraries = new Libraries($runner);
        $libraries->ensure(self::instance());
        $libraries->ensure(self::instance());
        $prefix = ['/bin/fake', '--host', 'unix:///var/run/docker.sock', 'compose', '--project-name', 'libraries-test', '--file', '/disposable/compose.json', 'exec', '-T', 'app', 'php', 'bin/console'];
        self::assertSame([
            [...$prefix, 'app:library:list', '--json'],
            [...$prefix, 'app:library:create', '--slug', 'music', '--json', '--', ' Music ', '/media/music', 'music'],
            [...$prefix, 'app:library:list', '--json'],
        ], $commands);
    }

    public function testLeadingHyphenNameRemainsAnArgumentInsteadOfRequestingHelp(): void
    {
        $instance = self::instance();
        $instance['libraries'][0]['name'] = '--help';
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::exactly(2))->method('run')->willReturnCallback(static function (\Baander\Tooling\Process\ProcessSpec $command): ProcessResult {
            $arguments = $command->argv;
            if (in_array('app:library:list', $arguments, true)) {
                return new ProcessResult(0, null, false, false, '[]', '', 0);
            }
            $position = array_search('app:library:create', $arguments, true);
            self::assertIsInt($position);
            $input = new ArgvInput(['bin/console', ...array_slice($arguments, $position + 1)]);
            self::assertFalse($input->hasParameterOption('--help', true));
            $input->bind(new InputDefinition([
                new InputArgument('name', InputArgument::REQUIRED),
                new InputArgument('path', InputArgument::REQUIRED),
                new InputArgument('type', InputArgument::REQUIRED),
                new InputOption('slug', mode: InputOption::VALUE_REQUIRED),
                new InputOption('json'),
            ]));
            self::assertSame('--help', $input->getArgument('name'));
            self::assertSame('/media/music', $input->getArgument('path'));
            self::assertSame('music', $input->getArgument('type'));
            self::assertSame('music', $input->getOption('slug'));
            self::assertTrue($input->getOption('json'));
            return new ProcessResult(0, null, false, false, '{}', '', 0);
        });
        new Libraries($runner)->ensure($instance);
    }

    public function testEmptyOrAbsentDefinitionsNeverRunCommands(): void
    {
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::never())->method('run');
        $libraries = new Libraries($runner);
        $libraries->ensure([]);
        $libraries->ensure(['libraries' => []]);
    }

    #[DataProvider('driftCases')]
    public function testDriftFailsWithoutCreatingOrUpdating(string $field, string $value): void
    {
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::once())->method('run')->willReturn(new ProcessResult(
            0,
            null,
            false,
            false,
            json_encode([array_replace(self::existing(), [$field => $value])], JSON_THROW_ON_ERROR),
            '',
            0,
        ));
        $this->expectException(WorkflowError::class);
        $this->expectExceptionMessage('music');
        new Libraries($runner)->ensure(self::instance());
    }

    /** @return iterable<string,array{string,string}> */
    public static function driftCases(): iterable
    {
        yield 'name' => ['name', 'Renamed'];
        yield 'type' => ['type', 'podcast'];
        yield 'path' => ['path', '/other'];
        yield 'filesystem' => ['filesystemType', 'remote'];
    }

    public function testLaterConflictPreventsCreatingAnEarlierMissingLibrary(): void
    {
        $instance = self::instance();
        array_unshift($instance['libraries'], [
            'slug' => 'movies', 'name' => 'Movies', 'type' => 'movie',
            'source' => '/host/movies', 'target' => '/media/movies',
        ]);
        $catalog = [array_replace(self::existing(), ['name' => 'Conflicting name'])];
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::once())->method('run')->willReturn(
            new ProcessResult(0, null, false, false, json_encode($catalog, JSON_THROW_ON_ERROR), '', 0),
        );
        $this->expectExceptionMessage('conflicting name');
        new Libraries($runner)->ensure($instance);
    }

    #[DataProvider('invalidLists')]
    public function testInvalidListFailsWithoutCreation(string $output): void
    {
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::once())->method('run')->willReturn(new ProcessResult(0, null, false, false, $output, '', 0));
        $this->expectException(WorkflowError::class);
        $this->expectExceptionMessage('list');
        new Libraries($runner)->ensure(self::instance());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLists(): iterable
    {
        yield 'not json' => ['invalid'];
        yield 'object' => ['{}'];
        yield 'non-list' => ['{"result":[]}'];
        yield 'invalid row' => ['[{}]'];
    }

    public function testListCommandFailureHasClearContext(): void
    {
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::once())->method('run')->willThrowException(new WorkflowError('fake exited with status 1.'));
        $this->expectExceptionMessage('list');
        new Libraries($runner)->ensure(self::instance());
    }

    public function testCreateCommandFailureNamesTheLibrary(): void
    {
        $runner = $this->createMock(DevelopmentCommandExecutor::class);
        $runner->expects(self::exactly(2))->method('run')->willReturnCallback(static function (\Baander\Tooling\Process\ProcessSpec $command): ProcessResult {
            $arguments = $command->argv;
            if (in_array('app:library:create', $arguments, true)) {
                throw new WorkflowError('fake exited with status 1.');
            }
            return new ProcessResult(0, null, false, false, '[]', '', 0);
        });
        $this->expectExceptionMessage('music');
        new Libraries($runner)->ensure(self::instance());
    }
}
