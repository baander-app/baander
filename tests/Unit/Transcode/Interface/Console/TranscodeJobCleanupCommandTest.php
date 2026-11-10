<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Console;

use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Service\StaleTranscodeSessionExpiry;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Interface\Console\TranscodeJobCleanupCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class TranscodeJobCleanupCommandTest extends TestCase
{
    public function testJsonIsTheDataTheCleanupEndpointReturns(): void
    {
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('cleanupOrphanedJobs')->willReturn(3);
        $support = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CleanupOrphanedJobsCommand::class => [new CleanupOrphanedJobsHandler($jobs, $this->staleSessions(2))],
        ]))]));

        $tester = new CommandTester(new TranscodeJobCleanupCommand($support));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame(['expired' => 2, 'cleaned' => 3], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTextReportsExpiredSessionsAndRemovedJobs(): void
    {
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('cleanupOrphanedJobs')->willReturn(0);
        $support = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CleanupOrphanedJobsCommand::class => [new CleanupOrphanedJobsHandler($jobs, $this->staleSessions(8))],
        ]))]));

        $tester = new CommandTester(new TranscodeJobCleanupCommand($support));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Expired 8 stale transcode sessions and removed 0 orphaned transcode jobs.', $tester->getDisplay());
    }

    /** An expiry over $count abandoned sessions, each without an execution of its own. */
    private function staleSessions(int $count): StaleTranscodeSessionExpiry
    {
        $now = new \DateTimeImmutable('2026-11-10 12:00:00+00');
        $stale = [];
        for ($index = 0; $index < $count; $index++) {
            $session = TranscodeSession::create(Uuid::generate(), Uuid::generate(), Uuid::generate(), AudioProfile::streamingStereo());
            $session->getState()->updatedAt = $now->modify('-2 days');
            $stale[$session->getId()->toString()] = $session;
        }
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findActive')->willReturn(array_values($stale));
        $sessions->method('findByUuid')->willReturnCallback(static fn (Uuid $id): ?TranscodeSession => $stale[$id->toString()] ?? null);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return new StaleTranscodeSessionExpiry($sessions, $this->createStub(TranscodeExecutionOwnershipPort::class),
            $this->createStub(TranscodeJobPortInterface::class), $transaction, new MockClock($now));
    }
}
