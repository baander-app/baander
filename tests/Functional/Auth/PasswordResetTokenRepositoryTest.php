<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class PasswordResetTokenRepositoryTest extends TestCase
{
    public function testRedeemingReturnsTheUserOnceAndStoresOnlyAHash(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), 'raw-token-a', new \DateTimeImmutable('+1 hour'));

        $stored = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM password_reset_tokens');
        $this->assertIsArray($stored);
        $this->assertSame(hash('sha256', 'raw-token-a'), $stored['token_hash']);
        $this->assertNotContains('raw-token-a', $stored);

        $redeemed = $this->tokens()->redeem('raw-token-a', new \DateTimeImmutable());
        $this->assertNotNull($redeemed);
        $this->assertTrue($user->getId()->equals($redeemed));
        $this->assertNull($this->tokens()->redeem('raw-token-a', new \DateTimeImmutable()), 'A token is single use.');
        $this->assertNull($this->tokens()->redeem('unknown-token', new \DateTimeImmutable()));
    }

    public function testATokenExpiresAtItsExpiryInstant(): void
    {
        $user = $this->createTestUser();
        $expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05+00:00');

        $this->tokens()->issue($user->getId(), 'raw-token-b', $expiresAt);
        $this->assertNull($this->tokens()->redeem('raw-token-b', $expiresAt), 'The expiry instant is already too late.');
        $this->assertSame(0, $this->outstanding(), 'An expired token is removed when presented.');

        $this->tokens()->issue($user->getId(), 'raw-token-b', $expiresAt);
        $this->assertNotNull($this->tokens()->redeem('raw-token-b', $expiresAt->modify('-1 second')));
    }

    public function testIssuingReplacesTheUsersEarlierToken(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), 'first-token', new \DateTimeImmutable('+1 hour'));
        $this->tokens()->issue($user->getId(), 'second-token', new \DateTimeImmutable('+1 hour'));

        $this->assertSame(1, $this->outstanding());
        $this->assertNull($this->tokens()->redeem('first-token', new \DateTimeImmutable()));
        $this->assertNotNull($this->tokens()->redeem('second-token', new \DateTimeImmutable()));
    }

    public function testRevokingRemovesOnlyThatUsersToken(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $this->tokens()->issue($owner->getId(), 'owner-token', new \DateTimeImmutable('+1 hour'));
        $this->tokens()->issue($other->getId(), 'other-token', new \DateTimeImmutable('+1 hour'));

        $this->tokens()->revokeForUser($owner->getId());
        $this->tokens()->revokeForUser(Uuid::generate());

        $this->assertNull($this->tokens()->redeem('owner-token', new \DateTimeImmutable()));
        $this->assertNotNull($this->tokens()->redeem('other-token', new \DateTimeImmutable()));
    }

    public function testATokenCannotRedeemForALaterAccountWithTheSameEmail(): void
    {
        $email = 'recycled-' . bin2hex(random_bytes(4)) . '@baander.app';
        $first = $this->createTestUser($email);
        $this->tokens()->issue($first->getId(), 'recycled-token', new \DateTimeImmutable('+1 hour'));
        $this->userRepository->delete($first->getId());

        $this->createTestUser($email);

        $this->assertNull($this->tokens()->redeem('recycled-token', new \DateTimeImmutable()));
    }

    private function outstanding(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM password_reset_tokens');
    }

    private function tokens(): PasswordResetTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(PasswordResetTokenRepositoryInterface::class);
        $this->assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
