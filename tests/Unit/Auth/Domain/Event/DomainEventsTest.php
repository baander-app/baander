<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Event;

use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Event\Passkey\PasskeyDeleted;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Event\UserRegistered;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DomainEventsTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
    }

    // --- UserRegistered ---

    public function testUserRegisteredToPayloadAndBack(): void
    {
        $event = new UserRegistered(
            Uuid::v4(),
            new PublicId(),
            new Email('test@example.com'),
            'Alice',
            $this->now,
        );

        $payload = $event->toPayload();

        $restored = UserRegistered::fromPayload($payload);

        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame($event->getPublicId()->toString(), $restored->getPublicId()->toString());
        $this->assertSame($event->getEmail()->toString(), $restored->getEmail()->toString());
        $this->assertSame('Alice', $restored->getName());
    }

    // --- PasswordChanged ---

    public function testPasswordChangedToPayloadAndBack(): void
    {
        $event = new PasswordChanged(Uuid::v4(), new Email('test@example.com'), $this->now);
        $payload = $event->toPayload();
        $restored = PasswordChanged::fromPayload($payload);

        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame($event->getEmail()->toString(), $restored->getEmail()->toString());
    }

    // --- EmailVerified ---

    public function testEmailVerifiedToPayloadAndBack(): void
    {
        $event = new EmailVerified(Uuid::v4(), new Email('test@example.com'), $this->now);
        $payload = $event->toPayload();
        $restored = EmailVerified::fromPayload($payload);

        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame($event->getEmail()->toString(), $restored->getEmail()->toString());
    }

    // --- PasskeyRegistered ---

    public function testPasskeyRegisteredToPayloadAndBack(): void
    {
        $event = new PasskeyRegistered(
            Uuid::v4(),
            Uuid::v4(),
            'cred-id-123',
            'My Key',
            $this->now,
        );
        $payload = $event->toPayload();
        $restored = PasskeyRegistered::fromPayload($payload);

        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame($event->getPasskeyId()->toString(), $restored->getPasskeyId()->toString());
        $this->assertSame('cred-id-123', $restored->getCredentialId());
        $this->assertSame('My Key', $restored->getName());
    }

    // --- PasskeyDeleted ---

    public function testPasskeyDeletedToPayloadAndBack(): void
    {
        $event = new PasskeyDeleted(Uuid::v4(), Uuid::v4(), $this->now);
        $payload = $event->toPayload();
        $restored = PasskeyDeleted::fromPayload($payload);

        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame($event->getPasskeyId()->toString(), $restored->getPasskeyId()->toString());
    }

    public function testOccurredAtReturnsSameTime(): void
    {
        $event = new UserRegistered(Uuid::v4(), new PublicId(), new Email('t@t.com'), 'A', $this->now);

        $this->assertEquals($this->now, $event->occurredAt());
    }
}
