<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Library\Application\Query\LibraryMembershipQueryPort;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Application\Handler\CreateNotificationHandler;
use App\Notification\Application\Service\NotificationContentResolver;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateNotificationHandlerTest extends TestCase
{
    private EventCategoryResolver $categoryResolver;
    private NotificationContentResolver $contentResolver;
    private TranslatorInterface&Stub $translator;
    private NotificationRepositoryInterface&Stub $notificationRepository;
    private LibraryMembershipQueryPort&Stub $libraryMembershipQuery;
    private UserRepositoryInterface&Stub $userRepository;
    private MessageBusInterface&Stub $bus;

    protected function setUp(): void
    {
        $this->categoryResolver = new EventCategoryResolver();
        $this->contentResolver = new NotificationContentResolver();
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->notificationRepository = $this->createStub(NotificationRepositoryInterface::class);
        $this->libraryMembershipQuery = $this->createStub(LibraryMembershipQueryPort::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->bus = $this->createStub(MessageBusInterface::class);

        $this->translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = [], ?string $domain = null, ?string $locale = null) => $id,
        );
    }

    private function createHandler(): CreateNotificationHandler
    {
        return new CreateNotificationHandler(
            $this->categoryResolver,
            $this->contentResolver,
            $this->translator,
            $this->notificationRepository,
            $this->libraryMembershipQuery,
            $this->userRepository,
            $this->bus,
        );
    }

    public function testPasswordChangedCreatesNotificationForUser(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $userId = Uuid::generate();
        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\PasswordChanged::class,
            payload: ['user_id' => $userId->toString(), 'email' => 'test@baander.app', 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'user.password_changed',
        );

        $this->notificationRepository->expects($this->once())->method('save');
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }

    public function testLibraryScanCreatesNotificationsForAllLibraryUsers(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $libraryId = Uuid::generate();
        $user1 = Uuid::generate();
        $user2 = Uuid::generate();

        $this->libraryMembershipQuery->method('findUserIdsForLibrary')
            ->willReturn([$user1->toString(), $user2->toString()]);

        $command = new CreateNotificationCommand(
            eventClass: \App\Library\Domain\Event\LibraryScanCompleted::class,
            payload: ['library_id' => $libraryId->toString(), 'files_discovered' => 150, 'files_processed' => 120, 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'library.scan_completed',
        );

        $this->notificationRepository->expects($this->exactly(2))->method('save');
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }

    public function testUnmappedEventProducesNoNotification(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\EmailVerified::class,
            payload: ['user_id' => Uuid::generate()->toString(), 'email' => 'listener@baander.app', 'occurred_at' => '2026-10-07T00:00:00+00:00'],
            eventName: 'user.email_verified',
        );

        $this->notificationRepository->expects($this->never())->method('save');

        $this->createHandler()($command);
    }

    public function testLibraryWithNoUsersCreatesNoNotification(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $libraryId = Uuid::generate();

        $this->libraryMembershipQuery->method('findUserIdsForLibrary')
            ->willReturn([]);

        $command = new CreateNotificationCommand(
            eventClass: \App\Library\Domain\Event\LibraryScanCompleted::class,
            payload: ['library_id' => $libraryId->toString(), 'files_discovered' => 10, 'files_processed' => 5, 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'library.scan_completed',
        );

        $this->notificationRepository->expects($this->never())->method('save');

        $this->createHandler()($command);
    }

    public function testEventWithoutUserIdAndNotLibraryScopedCreatesNoNotification(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\OAuth\TokenRevoked::class,
            payload: ['token_id' => 'abc', 'token_type' => 'access_token'],
            eventName: 'token.revoked',
        );

        $this->notificationRepository->expects($this->never())->method('save');

        $this->createHandler()($command);
    }

    public function testLibraryScanStoresParametersWithFileCounts(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepositoryInterface::class);

        $userId = Uuid::generate();
        $libraryId = Uuid::generate();

        $this->libraryMembershipQuery->method('findUserIdsForLibrary')
            ->willReturn([$userId->toString()]);

        $command = new CreateNotificationCommand(
            eventClass: \App\Library\Domain\Event\LibraryScanCompleted::class,
            payload: ['library_id' => $libraryId->toString(), 'files_discovered' => 200, 'files_processed' => 180, 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'library.scan_completed',
        );

        $this->notificationRepository->expects($this->once())->method('save')
            ->with($this->callback(function (Notification $notification) {
                $params = $notification->getParameters();
                return $params !== null
                    && ($params['body']['filesDiscovered'] ?? null) === 200
                    && ($params['body']['filesProcessed'] ?? null) === 180;
            }));
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }

    public function testEmailCommandDispatchedForVerifiedUser(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $userId = Uuid::generate();

        $user = User::reconstitute(new UserState(
            id: $userId,
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: 'Test',
            email: 'user@baander.app',
            password: 'hashed',
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            emailVerifiedAt: new \DateTimeImmutable('-1 day'),
        ));

        $this->userRepository->expects($this->once())->method('findByUuid')->with($userId)->willReturn($user);

        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\PasswordChanged::class,
            payload: ['user_id' => $userId->toString(), 'email' => 'user@baander.app', 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'user.password_changed',
        );

        // Expects email + push + webhook dispatches (3 total)
        $this->bus->expects($this->exactly(3))->method('dispatch')
            ->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }

    public function testEmailCommandNotDispatchedForUnverifiedUser(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $userId = Uuid::generate();

        $user = User::reconstitute(new UserState(
            id: $userId,
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: 'Test',
            email: 'user@baander.app',
            password: 'hashed',
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->userRepository->expects($this->once())->method('findByUuid')->with($userId)->willReturn($user);

        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\PasswordChanged::class,
            payload: ['user_id' => $userId->toString(), 'email' => 'user@baander.app', 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'user.password_changed',
        );

        // Push and webhook are still dispatched, only email is skipped
        $this->bus->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }

    public function testEmailCommandNotDispatchedWhenUserNotFound(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $userId = Uuid::generate();

        $this->userRepository->expects($this->once())->method('findByUuid')->with($userId)->willReturn(null);

        $command = new CreateNotificationCommand(
            eventClass: \App\Auth\Domain\Event\PasswordChanged::class,
            payload: ['user_id' => $userId->toString(), 'email' => 'user@baander.app', 'occurred_at' => '2026-04-19T00:00:00+00:00'],
            eventName: 'user.password_changed',
        );

        // Push and webhook are still dispatched, only email is skipped
        $this->bus->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->createHandler()($command);
    }
}
