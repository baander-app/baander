<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Console;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Interface\Console\TranscodeJobCleanupCommand;
use App\Transcode\Interface\Console\TranscodeSessionListCommand;
use App\Transcode\Interface\Console\TranscodeSessionShowCommand;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class TranscodeAdminCommandsTest extends TestCase
{
    public function testSessionListShowsTheActiveSessionsOfEveryUser(): void
    {
        $first = $this->session(Uuid::generate());
        $second = $this->session(Uuid::generate());
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::once())->method('findActive')->willReturn([$first, $second]);
        $sessions->expects(self::never())->method('findActiveByUser');
        $users = $this->createMock(UserIdentifierResolverInterface::class);
        $users->expects(self::never())->method('userId');

        $tester = new CommandTester(new TranscodeSessionListCommand($sessions, $users));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        foreach ([$first, $second] as $session) {
            self::assertStringContainsString($session->getId()->toString(), $display);
            self::assertStringContainsString($session->getUserId()->toString(), $display);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function userIdentifiers(): iterable
    {
        yield 'a UUID' => ['0199b0a4-3c55-7a8e-9f2b-6c1d4e5f7a80'];
        yield 'an email address' => ['viewer@baander.app'];
    }

    #[DataProvider('userIdentifiers')]
    public function testSessionListWithAUserListsOnlyTheSessionsOfTheUserAuthResolves(string $identifier): void
    {
        $owner = Uuid::generate();
        $own = $this->session($owner);
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('findActive');
        $sessions->expects(self::once())
            ->method('findActiveByUser')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($owner)))
            ->willReturn([$own]);
        $users = $this->createMock(UserIdentifierResolverInterface::class);
        $users->expects(self::once())->method('userId')->with($identifier)->willReturn($owner);

        $tester = new CommandTester(new TranscodeSessionListCommand($sessions, $users));

        self::assertSame(Command::SUCCESS, $tester->execute(['--user' => $identifier, '--json' => true]));
        self::assertJsonStringEqualsJsonString(json_encode(TranscodeSessionResource::collection([$own]), JSON_THROW_ON_ERROR), $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function unknownUsers(): iterable
    {
        yield 'an unknown email address' => ['nobody@baander.app'];
        yield 'an unknown UUID' => ['0199b0a4-3c55-7a8e-9f2b-6c1d4e5f7a81'];
        yield 'neither' => ['not-a-user'];
    }

    #[DataProvider('unknownUsers')]
    public function testSessionListFailsForAUserAuthDoesNotFind(string $identifier): void
    {
        $users = $this->createStub(UserIdentifierResolverInterface::class);
        $users->method('userId')->willThrowException(UserNotFoundException::forIdentifier($identifier));
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('findActiveByUser');
        $sessions->expects(self::never())->method('findActive');
        $tester = new CommandTester(new TranscodeSessionListCommand($sessions, $users));

        self::assertSame(Command::FAILURE, $tester->execute(['--user' => $identifier]));
        self::assertStringContainsString(sprintf('User "%s" not found.', $identifier), $tester->getDisplay());
    }

    public function testSessionShowPrintsTheSessionAsTheApiDoes(): void
    {
        $session = $this->session(Uuid::generate());
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::once())
            ->method('findByUuid')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($session->getId())))
            ->willReturn($session);

        $tester = new CommandTester(new TranscodeSessionShowCommand($sessions));

        self::assertSame(Command::SUCCESS, $tester->execute(['uuid' => $session->getId()->toString(), '--json' => true]));
        self::assertJsonStringEqualsJsonString(json_encode(TranscodeSessionResource::from($session), JSON_THROW_ON_ERROR), $tester->getDisplay());
    }

    public function testSessionShowReportsAnUnknownAndAMalformedSession(): void
    {
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn(null);
        $tester = new CommandTester(new TranscodeSessionShowCommand($sessions));

        self::assertSame(Command::FAILURE, $tester->execute(['uuid' => Uuid::generate()->toString()]));
        self::assertStringContainsString('Session not found.', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['uuid' => 'not-a-uuid']));
        self::assertStringContainsString('The session ID must be a UUID.', $tester->getDisplay());
    }

    public function testJobCleanupDispatchesTheCleanupTheApiDispatches(): void
    {
        $jobs = $this->createMock(TranscodeJobPortInterface::class);
        $jobs->expects(self::once())->method('cleanupOrphanedJobs')->willReturn(3);
        $support = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CleanupOrphanedJobsCommand::class => [new CleanupOrphanedJobsHandler($jobs)],
        ]))]));

        $tester = new CommandTester(new TranscodeJobCleanupCommand($support));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Removed 3 orphaned transcode jobs.', $tester->getDisplay());
    }

    private function session(Uuid $owner): TranscodeSession
    {
        return TranscodeSession::create($owner, Uuid::generate(), Uuid::generate(), AudioProfile::streamingStereo());
    }
}
