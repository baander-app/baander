<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\LogViewer\LogViewerInterface;
use App\Shared\Application\LogViewer\TerminalLogGuardInterface;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Interface\Console\Log\LogQueryCommand;
use App\Shared\Interface\Console\Log\LogSourcesCommand;
use App\Shared\Interface\Console\Log\LogTerminalViewer;
use App\Shared\Interface\Console\Log\LogViewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LogCommandTest extends TestCase
{
    public function testSourceJsonIsExactPayload(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $data = ['items' => [['id' => 'archive']], 'coverage' => ['complete' => false]];
        $viewer->expects(self::once())->method('sources')->willReturn($data);
        $tester = new CommandTester(new LogSourcesCommand($viewer));
        self::assertSame(0, $tester->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame($data, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testSqlAndFiltersReachTheSharedPort(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $viewer->expects(self::once())->method('query')->with(self::callback(static fn ($query): bool => $query->sql === 'SELECT level, count(*) FROM logs GROUP BY level' && $query->allowPartial && $query->filter->sources === ['archive'] && $query->filter->channels === ['worker', 'scheduler'] && $query->filter->limit === 200 && $query->filter->cursor === null))->willReturn(['columns' => ['level'], 'rows' => [], 'coverage' => []]);
        $tester = new CommandTester(new LogQueryCommand($viewer));
        self::assertSame(0, $tester->execute(['sql' => 'SELECT level, count(*) FROM logs GROUP BY level', '--source' => ['archive'], '--channel' => ['worker', 'scheduler'], '--allow-partial' => true, '--json' => true]));
        self::assertArrayHasKey('columns', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInvalidLimitWritesOnlyStderr(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $viewer->expects(self::never())->method('entries');
        $terminal = new LogTerminalViewer($viewer, $this->createStub(SleeperInterface::class), $this->createStub(TerminalLogGuardInterface::class));
        $tester = new CommandTester(new LogViewCommand($viewer, $terminal));
        self::assertSame(2, $tester->execute(['--limit' => 'invalid', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('--limit must be an integer', $tester->getErrorOutput());
    }

    public function testBrowseAcceptsMultipleChannels(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $viewer->expects(self::once())->method('entries')->with(self::callback(static fn ($query): bool => $query->channels === ['worker', 'scheduler']))->willReturn(['items' => [], 'coverage' => []]);
        $terminal = new LogTerminalViewer($viewer, $this->createStub(SleeperInterface::class), $this->createStub(TerminalLogGuardInterface::class));
        $tester = new CommandTester(new LogViewCommand($viewer, $terminal));
        self::assertSame(0, $tester->execute(['--channel' => ['worker', 'scheduler'], '--json' => true]));
    }

    public function testDetailJsonUsesSharedDetail(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $viewer->expects(self::once())->method('detail')->with('opaque-record')->willReturn(['raw' => "\033[31mraw"]);
        $terminal = new LogTerminalViewer($viewer, $this->createStub(SleeperInterface::class), $this->createStub(TerminalLogGuardInterface::class));
        $tester = new CommandTester(new LogViewCommand($viewer, $terminal));
        self::assertSame(0, $tester->execute(['--detail' => 'opaque-record', '--json' => true]));
        self::assertSame(['raw' => "\033[31mraw"], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSqlDoesNotAdvertiseBrowsePaginationOptions(): void
    {
        $command = new LogQueryCommand($this->createStub(LogViewerInterface::class));
        self::assertFalse($command->getDefinition()->hasOption('cursor'));
        self::assertFalse($command->getDefinition()->hasOption('limit'));
    }

    public function testSharedValidationFailureUsesInvalidExit(): void
    {
        $viewer = $this->createMock(LogViewerInterface::class);
        $viewer->expects(self::once())->method('query')->willThrowException(new InvalidInputException('SQL writes are forbidden.'));
        $tester = new CommandTester(new LogQueryCommand($viewer));
        self::assertSame(2, $tester->execute(['sql' => 'DELETE FROM logs', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
    }
}
