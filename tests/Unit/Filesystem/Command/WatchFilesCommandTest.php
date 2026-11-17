<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Command;

use App\Filesystem\Command\WatchFilesCommand;
use App\Filesystem\Watcher\FileWatcher;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the command's configuration and the directory it watches without --path.
 *
 * FileWatcher::run() blocks until SIGTERM or SIGINT, and FileWatcher is final, so the
 * execution test sends this process a SIGTERM from an alarm once the watcher runs.
 */
final class WatchFilesCommandTest extends TestCase
{
    private WatchFilesCommand $command;
    private ?string $previousDirectory = null;
    private ?string $directory = null;
    private bool $asyncSignals = false;
    /** @var array<int, mixed> the signal handlers to restore, by signal */
    private array $signalHandlers = [];

    protected function setUp(): void
    {
        $this->command = new WatchFilesCommand(
            new FileWatcher(new NullLogger()),
        );
    }

    protected function tearDown(): void
    {
        if ($this->directory === null) {
            return;
        }

        pcntl_alarm(0);
        foreach ($this->signalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($this->asyncSignals);
        if ($this->previousDirectory !== null) {
            chdir($this->previousDirectory);
        }
        rmdir($this->directory);
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

    #[RequiresPhpExtension('inotify')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('swoole')]
    public function testWithoutPathWatchesTheCurrentDirectory(): void
    {
        $this->previousDirectory = getcwd() ?: null;
        $this->asyncSignals = pcntl_async_signals();
        foreach ([\SIGALRM, \SIGTERM, \SIGINT] as $signal) {
            $this->signalHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->directory = sys_get_temp_dir() . '/baander-watch-files-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        self::assertTrue(chdir($this->directory));

        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $tester = new CommandTester(new WatchFilesCommand(new FileWatcher($logger)));

        // The watcher's own SIGTERM handler ends its loop.
        pcntl_signal(\SIGALRM, static function (): void {
            \Swoole\Process::kill(getmypid(), \SIGTERM);
        });
        pcntl_alarm(1);

        self::assertSame(Command::SUCCESS, $tester->execute(['--timeout' => '50']));
        self::assertStringContainsString('Watching current directory', $tester->getDisplay());
        $watch = array_find($logger->records, static fn (array $record): bool => $record[0] === 'Watching directory');
        self::assertSame(realpath($this->directory), $watch[1]['path'] ?? null);
    }
}
