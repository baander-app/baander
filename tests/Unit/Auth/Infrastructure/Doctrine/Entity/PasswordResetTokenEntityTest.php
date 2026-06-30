<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\PasswordResetTokenEntity;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenEntityTest extends TestCase
{
    public function testFreshTokenIsNotExpired(): void
    {
        $token = new PasswordResetTokenEntity('user@example.com', 'some-token');

        $this->assertNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }

    public function testTokenWithFutureExpirationIsNotExpired(): void
    {
        $token = new PasswordResetTokenEntity('user@example.com', 'some-token');
        $token->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($token->isExpired());
    }

    public function testTokenWithPastExpirationIsExpired(): void
    {
        $token = new PasswordResetTokenEntity('user@example.com', 'some-token');
        $token->setExpiresAt(new \DateTimeImmutable('-1 minute'));

        $this->assertTrue($token->isExpired());
    }

    public function testTokenWithExactlyNowExpirationIsNotExpired(): void
    {
        $token = new PasswordResetTokenEntity('user@example.com', 'some-token');
        $token->setExpiresAt(new \DateTimeImmutable('+1 second'));

        // isExpired uses strict less-than (<), so "now + 1s" is not yet expired
        $this->assertFalse($token->isExpired());
    }

    public function testSetExpiresAtOverridesPreviousValue(): void
    {
        $token = new PasswordResetTokenEntity('user@example.com', 'some-token');
        $future = new \DateTimeImmutable('+2 hours');
        $token->setExpiresAt($future);

        $this->assertEquals($future, $token->getExpiresAt());

        $past = new \DateTimeImmutable('-1 day');
        $token->setExpiresAt($past);

        $this->assertEquals($past, $token->getExpiresAt());
    }
}
