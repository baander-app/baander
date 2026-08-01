<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain\Model;

use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testCreateInitializesWithDefaults(): void
    {
        $userId = Uuid::generate();
        $notification = Notification::create(
            userId: $userId,
            category: NotificationCategory::Security,
            eventType: 'user.passkey_registered',
            title: 'Passkey registered',
            body: 'A new passkey was registered on your account.',
        );

        $this->assertInstanceOf(Uuid::class, $notification->getId());
        $this->assertInstanceOf(PublicId::class, $notification->getPublicId());
        $this->assertSame($userId->toString(), $notification->getUserId()->toString());
        $this->assertSame(NotificationCategory::Security, $notification->getCategory());
        $this->assertSame('user.passkey_registered', $notification->getEventType());
        $this->assertSame('Passkey registered', $notification->getTitle());
        $this->assertSame('A new passkey was registered on your account.', $notification->getBody());
        $this->assertFalse($notification->isRead());
        $this->assertNull($notification->getReferenceData());
        $this->assertInstanceOf(DateTimeImmutable::class, $notification->getCreatedAt());
    }

    public function testCreateWithReferenceData(): void
    {
        $userId = Uuid::generate();
        $referenceData = ['libraryId' => Uuid::generate()->toString(), 'filesProcessed' => 42];

        $notification = Notification::create(
            userId: $userId,
            category: NotificationCategory::BackgroundJobs,
            eventType: 'library.scan_completed',
            title: 'Library scan completed',
            body: '42 files processed.',
            referenceData: $referenceData,
        );

        $this->assertSame($referenceData, $notification->getReferenceData());
    }

    public function testReconstituteFromPersistence(): void
    {
        $id = Uuid::generate();
        $userId = Uuid::generate();
        $createdAt = new DateTimeImmutable('2026-04-19T12:00:00+00:00');

        $publicId = new PublicId();

        $notification = Notification::reconstitute(
            id: $id,
            publicId: $publicId,
            userId: $userId,
            category: NotificationCategory::MediaChanges,
            eventType: 'album.created',
            title: 'New album',
            body: 'Album added.',
            isRead: true,
            createdAt: $createdAt,
            referenceData: ['albumId' => 'abc'],
        );

        $this->assertSame($id->toString(), $notification->getId()->toString());
        $this->assertSame($publicId->toString(), $notification->getPublicId()->toString());
        $this->assertSame($userId->toString(), $notification->getUserId()->toString());
        $this->assertSame(NotificationCategory::MediaChanges, $notification->getCategory());
        $this->assertSame('album.created', $notification->getEventType());
        $this->assertSame('New album', $notification->getTitle());
        $this->assertSame('Album added.', $notification->getBody());
        $this->assertTrue($notification->isRead());
        $this->assertSame(['albumId' => 'abc'], $notification->getReferenceData());
        $this->assertSame($createdAt->getTimestamp(), $notification->getCreatedAt()->getTimestamp());
    }

    public function testMarkAsRead(): void
    {
        $notification = Notification::create(
            userId: Uuid::generate(),
            category: NotificationCategory::Security,
            eventType: 'user.password_changed',
            title: 'Password changed',
            body: 'Your password was changed.',
        );

        $this->assertFalse($notification->isRead());

        $notification->markAsRead();

        $this->assertTrue($notification->isRead());
    }

    public function testMarkAsReadIsIdempotent(): void
    {
        $notification = Notification::create(
            userId: Uuid::generate(),
            category: NotificationCategory::Security,
            eventType: 'user.password_changed',
            title: 'Password changed',
            body: 'Your password was changed.',
        );

        $notification->markAsRead();
        $notification->markAsRead();

        $this->assertTrue($notification->isRead());
    }
}
