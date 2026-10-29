<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Libraries;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentLibrariesTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function instance(): array
    {
        return [
            'docker' => ['executable' => 'fake', 'context' => 'owned'],
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
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::exactly(3))->method('run')->willReturnCallback(static function (array $arguments) use (&$commands, &$responses): ProcessResult {
            $commands[] = $arguments;
            return new ProcessResult(0, null, false, false, array_shift($responses), '', 0);
        });
        $libraries = new Libraries($runner);
        $libraries->ensure(self::instance());
        $libraries->ensure(self::instance());
        $prefix = ['fake', '--context', 'owned', 'compose', '--project-name', 'libraries-test', '--file', '/disposable/compose.json', 'exec', '-T', 'app', 'php', 'bin/console'];
        self::assertSame([
            [...$prefix, 'app:library:list', '--json'],
            [...$prefix, 'app:library:create', ' Music ', '/media/music', 'music', '--slug', 'music', '--json'],
            [...$prefix, 'app:library:list', '--json'],
        ], $commands);
    }

    public function testEmptyOrAbsentDefinitionsNeverRunCommands(): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::never())->method('run');
        $libraries = new Libraries($runner);
        $libraries->ensure([]);
        $libraries->ensure(['libraries' => []]);
    }

    #[DataProvider('driftCases')]
    public function testDriftFailsWithoutCreatingOrUpdating(string $field, string $value): void
    {
        $runner = $this->createMock(Runner::class);
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

    public static function driftCases(): iterable
    {
        yield 'name' => ['name', 'Renamed'];
        yield 'type' => ['type', 'podcast'];
        yield 'path' => ['path', '/other'];
        yield 'filesystem' => ['filesystemType', 'remote'];
    }

    #[DataProvider('invalidLists')]
    public function testInvalidListFailsWithoutCreation(string $output): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::once())->method('run')->willReturn(new ProcessResult(0, null, false, false, $output, '', 0));
        $this->expectException(WorkflowError::class);
        $this->expectExceptionMessage('list');
        new Libraries($runner)->ensure(self::instance());
    }

    public static function invalidLists(): iterable
    {
        yield 'not json' => ['invalid'];
        yield 'object' => ['{}'];
        yield 'non-list' => ['{"result":[]}'];
        yield 'invalid row' => ['[{}]'];
    }

    public function testListCommandFailureHasClearContext(): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::once())->method('run')->willThrowException(new WorkflowError('fake exited with status 1.'));
        $this->expectExceptionMessage('list');
        new Libraries($runner)->ensure(self::instance());
    }

    public function testCreateCommandFailureNamesTheLibrary(): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::exactly(2))->method('run')->willReturnCallback(static function (array $arguments): ProcessResult {
            if (in_array('app:library:create', $arguments, true)) {
                throw new WorkflowError('fake exited with status 1.');
            }
            return new ProcessResult(0, null, false, false, '[]', '', 0);
        });
        $this->expectExceptionMessage('music');
        new Libraries($runner)->ensure(self::instance());
    }
}
