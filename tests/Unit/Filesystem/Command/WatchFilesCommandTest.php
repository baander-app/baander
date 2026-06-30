<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Command;

use App\Filesystem\Command\WatchFilesCommand;
use App\Filesystem\Watcher\FileWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the command's configuration (name, description, options).
 *
 * execute() is intentionally not covered: it delegates to FileWatcher::run()
 * which blocks indefinitely waiting for inotify events and only returns on
 * SIGTERM/SIGINT. FileWatcher is final and therefore cannot be mocked, so
 * driving execute() in a unit test would hang forever.
 */
final class WatchFilesCommandTest extends TestCase
{
    private WatchFilesCommand $command;

    protected function setUp(): void
    {
        $this->command = new WatchFilesCommand(
            new FileWatcher(new NullLogger()),
            new NullLogger(),
        );
    }

    public function testNameIsAppWatchFiles(): void
    {
        $this->assertSame('app:watch-files', $this->command->getName());
    }

    public function testDescriptionAnnouncesFileWatching(): void
    {
        $this->assertStringContainsString('Watch directories', $this->command->getDescription());
    }

    public function testPathOptionIsConfiguredAsArray(): void
    {
        $option = $this->command->getDefinition()->getOption('path');

        $this->assertTrue($this->command->getDefinition()->hasOption('path'));
        $this->assertTrue($option->isArray());
        $this->assertSame([], $option->getDefault());
        $this->assertSame('p', $option->getShortcut());
    }

    public function testTimeoutOptionDefaultsToStringMilliseconds(): void
    {
        $option = $this->command->getDefinition()->getOption('timeout');

        $this->assertTrue($this->command->getDefinition()->hasOption('timeout'));
        $this->assertFalse($option->isArray());
        $this->assertSame('5000', $option->getDefault());
        $this->assertSame('t', $option->getShortcut());
    }

    public function testPathAndTimeoutAreValueRequiredOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->getOption('path')->isValueRequired());
        $this->assertTrue($definition->getOption('timeout')->isValueRequired());
    }
}
