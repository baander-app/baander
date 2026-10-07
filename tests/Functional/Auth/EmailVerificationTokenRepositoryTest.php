<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class EmailVerificationTokenRepositoryTest extends TestCase
{
    public function testRedeemingReturnsTheUserAndAddressOnceAndStoresOnlyAHash(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), new Email($user->getEmail()), 'raw-token-a', new \DateTimeImmutable('+1 hour'));

        $stored = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM email_verification_tokens');
        $this->assertIsArray($stored);
        $this->assertSame(hash('sha256', 'raw-token-a'), $stored['token_hash']);
        $this->assertSame($user->getEmail(), $stored['email']);
        $this->assertNotContains('raw-token-a', $stored);

        $redeemed = $this->tokens()->redeem('raw-token-a', new \DateTimeImmutable());
        $this->assertNotNull($redeemed);
        $this->assertTrue($user->getId()->equals($redeemed->userId));
        $this->assertSame($user->getEmail(), $redeemed->email->toString());
        $this->assertNull($this->tokens()->redeem('raw-token-a', new \DateTimeImmutable()), 'A token is single use.');
        $this->assertNull($this->tokens()->redeem('unknown-token', new \DateTimeImmutable()));
    }

    public function testATokenExpiresAtItsExpiryInstant(): void
    {
        $user = $this->createTestUser();
        $email = new Email($user->getEmail());
        $expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05+00:00');

        $this->tokens()->issue($user->getId(), $email, 'raw-token-b', $expiresAt);
        $this->assertNull($this->tokens()->redeem('raw-token-b', $expiresAt), 'The expiry instant is already too late.');
        $this->assertSame(0, $this->outstanding(), 'An expired token is removed when presented.');

        $this->tokens()->issue($user->getId(), $email, 'raw-token-b', $expiresAt);
        $this->assertNotNull($this->tokens()->redeem('raw-token-b', $expiresAt->modify('-1 second')));
    }

    public function testIssuingReplacesTheUsersEarlierTokenAndAddress(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), new Email('first-' . $user->getEmail()), 'first-token', new \DateTimeImmutable('+1 hour'));
        $this->tokens()->issue($user->getId(), new Email($user->getEmail()), 'second-token', new \DateTimeImmutable('+1 hour'));

        $this->assertSame(1, $this->outstanding());
        $this->assertNull($this->tokens()->redeem('first-token', new \DateTimeImmutable()));
        $this->assertSame($user->getEmail(), $this->tokens()->redeem('second-token', new \DateTimeImmutable())?->email->toString());
    }

    public function testRevokingRemovesOnlyThatUsersToken(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $this->tokens()->issue($owner->getId(), new Email($owner->getEmail()), 'owner-token', new \DateTimeImmutable('+1 hour'));
        $this->tokens()->issue($other->getId(), new Email($other->getEmail()), 'other-token', new \DateTimeImmutable('+1 hour'));

        $this->tokens()->revokeForUser($owner->getId());
        $this->tokens()->revokeForUser(Uuid::generate());

        $this->assertNull($this->tokens()->redeem('owner-token', new \DateTimeImmutable()));
        $this->assertNotNull($this->tokens()->redeem('other-token', new \DateTimeImmutable()));
    }

    public function testChangingTheEmailRemovesTheTokenButRenamingKeepsIt(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), new Email($user->getEmail()), 'kept-token', new \DateTimeImmutable('+1 hour'));

        $user->updateName('Renamed User');
        $this->userRepository->save($user);
        $this->assertSame(1, $this->outstanding());

        $user->changeEmail('changed-' . bin2hex(random_bytes(4)) . '@baander.app');
        $this->userRepository->save($user);
        $this->assertSame(0, $this->outstanding());
    }

    public function testDeletingTheUserRemovesTheirToken(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), new Email($user->getEmail()), 'deleted-token', new \DateTimeImmutable('+1 hour'));

        $this->userRepository->delete($user->getId());

        $this->assertSame(0, $this->outstanding());
    }

    private function outstanding(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM email_verification_tokens');
    }

    private function tokens(): EmailVerificationTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(EmailVerificationTokenRepositoryInterface::class);
        $this->assertInstanceOf(EmailVerificationTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
