<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Interface\Controller\NotificationController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class NotificationControllerTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function foreignMutations(): iterable
    {
        foreach (['markRead', 'delete'] as $method) {
            foreach (['ROLE_USER', 'ROLE_ADMIN'] as $role) {
                foreach ([false, true] as $isRead) {
                    yield $method . ' ' . $role . ($isRead ? ' read' : ' unread') => [$method, [$role], $isRead];
                }
            }
        }
    }

    /** @param list<string> $roles */
    #[DataProvider('foreignMutations')]
    public function testForeignNotificationIsForbiddenWithoutMutationOrDisclosure(string $method, array $roles, bool $isRead): void
    {
        $notification = $this->notification(Uuid::generate());
        if ($isRead) {
            $notification->markAsRead();
        }
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->willReturn($notification);
        $repository->expects($this->never())->method('markAsRead');
        $repository->expects($this->never())->method('delete');
        $controller = $this->controller($repository, Uuid::generate(), $roles);

        $response = $controller->{$method}($notification->getPublicId()->toString());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($isRead, $notification->isRead());
        self::assertStringNotContainsString('Private notification title', (string) $response->getContent());
        self::assertStringNotContainsString('Private notification body', (string) $response->getContent());
    }

    public function testOwnerCanMarkNotificationRead(): void
    {
        $ownerId = Uuid::generate();
        $notification = $this->notification($ownerId);
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->willReturn($notification);
        $repository->expects($this->once())->method('markAsRead')->with($notification->getId());
        $repository->expects($this->never())->method('delete');

        $response = $this->controller($repository, $ownerId)->markRead($notification->getPublicId()->toString());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($notification->isRead());
        self::assertTrue(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['isRead']);
    }

    public function testOwnerReadOperationRemainsIdempotent(): void
    {
        $ownerId = Uuid::generate();
        $notification = $this->notification($ownerId);
        $notification->markAsRead();
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->willReturn($notification);
        $repository->expects($this->never())->method('markAsRead');
        $repository->expects($this->never())->method('delete');

        self::assertSame(200, $this->controller($repository, $ownerId)->markRead($notification->getPublicId()->toString())->getStatusCode());
    }

    public function testOwnerCanDeleteNotification(): void
    {
        $ownerId = Uuid::generate();
        $notification = $this->notification($ownerId);
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->willReturn($notification);
        $repository->expects($this->once())->method('delete')->with($notification);
        $repository->expects($this->never())->method('markAsRead');

        self::assertSame(204, $this->controller($repository, $ownerId)->delete($notification->getPublicId()->toString())->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function mutationMethods(): iterable
    {
        yield 'read' => ['markRead'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('mutationMethods')]
    public function testMissingNotificationRemainsNotFound(string $method): void
    {
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->willReturn(null);
        $repository->expects($this->never())->method('markAsRead');
        $repository->expects($this->never())->method('delete');

        self::assertSame(404, $this->controller($repository, Uuid::generate())->{$method}((new PublicId())->toString())->getStatusCode());
    }

    #[DataProvider('mutationMethods')]
    public function testInvalidPublicIdRemainsNotFoundWithoutRepositoryMutation(string $method): void
    {
        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects($this->never())->method('findByPublicId');
        $repository->expects($this->never())->method('markAsRead');
        $repository->expects($this->never())->method('delete');

        self::assertSame(404, $this->controller($repository, Uuid::generate())->{$method}('invalid-public-id')->getStatusCode());
    }

    private function notification(Uuid $ownerId): Notification
    {
        return Notification::create($ownerId, NotificationCategory::MediaChanges, 'media.imported', 'Private notification title', 'Private notification body');
    }

    /** @param list<string> $roles */
    private function controller(NotificationRepositoryInterface $repository, Uuid $userId, array $roles = ['ROLE_USER']): NotificationController
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'user@baander.app', 'hashed', $roles));

        return new NotificationController($repository, $security);
    }
}
