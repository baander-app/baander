<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain\ValueObject;

use App\Notification\Domain\ValueObject\NotificationChannel;
use PHPUnit\Framework\TestCase;

final class NotificationChannelTest extends TestCase
{
    public function testAllChannelsExist(): void
    {
        $this->assertSame(4, count(NotificationChannel::cases()));
    }

    public function testInAppValue(): void
    {
        $this->assertSame('in_app', NotificationChannel::InApp->value);
    }

    public function testEmailValue(): void
    {
        $this->assertSame('email', NotificationChannel::Email->value);
    }

    public function testPushValue(): void
    {
        $this->assertSame('push', NotificationChannel::Push->value);
    }

    public function testWebhookValue(): void
    {
        $this->assertSame('webhook', NotificationChannel::Webhook->value);
    }

    public function testFromValidString(): void
    {
        $channel = NotificationChannel::from('email');

        $this->assertSame(NotificationChannel::Email, $channel);
    }

    public function testCasesAreIterable(): void
    {
        $cases = NotificationChannel::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(NotificationChannel::InApp, $cases);
        $this->assertContains(NotificationChannel::Email, $cases);
        $this->assertContains(NotificationChannel::Push, $cases);
        $this->assertContains(NotificationChannel::Webhook, $cases);
    }

    public function testTryFromInvalidString(): void
    {
        $this->assertNull(NotificationChannel::tryFrom('invalid_channel'));
    }
}
