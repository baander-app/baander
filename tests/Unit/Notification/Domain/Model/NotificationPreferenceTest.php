<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain\Model;

use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $userId = Uuid::generate();
        $preference = NotificationPreference::create(
            userId: $userId,
            category: NotificationCategory::Security,
            channel: NotificationChannel::Email,
        );

        $this->assertInstanceOf(Uuid::class, $preference->getId());
        $this->assertSame($userId->toString(), $preference->getUserId()->toString());
        $this->assertSame(NotificationCategory::Security, $preference->getCategory());
        $this->assertSame(NotificationChannel::Email, $preference->getChannel());
        $this->assertTrue($preference->isEnabled());
    }

    public function testCreateDisabled(): void
    {
        $preference = NotificationPreference::create(
            userId: Uuid::generate(),
            category: NotificationCategory::MediaChanges,
            channel: NotificationChannel::Push,
            enabled: false,
        );

        $this->assertFalse($preference->isEnabled());
    }

    public function testReconstituteFromPersistence(): void
    {
        $id = Uuid::generate();
        $userId = Uuid::generate();

        $preference = NotificationPreference::reconstitute(
            id: $id,
            userId: $userId,
            category: NotificationCategory::BackgroundJobs,
            channel: NotificationChannel::InApp,
            enabled: true,
            createdAt: new \DateTimeImmutable('2026-04-19T12:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-04-19T12:00:00+00:00'),
        );

        $this->assertSame($id->toString(), $preference->getId()->toString());
        $this->assertSame(NotificationCategory::BackgroundJobs, $preference->getCategory());
        $this->assertSame(NotificationChannel::InApp, $preference->getChannel());
        $this->assertTrue($preference->isEnabled());
    }

    public function testEnable(): void
    {
        $preference = NotificationPreference::create(
            userId: Uuid::generate(),
            category: NotificationCategory::MediaChanges,
            channel: NotificationChannel::Push,
            enabled: false,
        );

        $preference->enable();

        $this->assertTrue($preference->isEnabled());
    }

    public function testDisable(): void
    {
        $preference = NotificationPreference::create(
            userId: Uuid::generate(),
            category: NotificationCategory::MediaChanges,
            channel: NotificationChannel::Push,
        );

        $preference->disable();

        $this->assertFalse($preference->isEnabled());
    }

    public function testEnableIsIdempotent(): void
    {
        $preference = NotificationPreference::create(
            userId: Uuid::generate(),
            category: NotificationCategory::Security,
            channel: NotificationChannel::Email,
        );

        $preference->enable();

        $this->assertTrue($preference->isEnabled());
    }

    public function testDisableIsIdempotent(): void
    {
        $preference = NotificationPreference::create(
            userId: Uuid::generate(),
            category: NotificationCategory::MediaChanges,
            channel: NotificationChannel::Push,
            enabled: false,
        );

        $preference->disable();

        $this->assertFalse($preference->isEnabled());
    }

    public function testDisableUpdatesTimestamp(): void
    {
        $preference = NotificationPreference::reconstitute(
            id: Uuid::generate(),
            userId: Uuid::generate(),
            category: NotificationCategory::Security,
            channel: NotificationChannel::Email,
            enabled: true,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $originalUpdatedAt = $preference->getUpdatedAt();

        $preference->disable();

        $this->assertGreaterThan($originalUpdatedAt->getTimestamp(), $preference->getUpdatedAt()->getTimestamp());
    }
}
