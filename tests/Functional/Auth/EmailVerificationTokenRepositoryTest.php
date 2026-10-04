<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\DTO\EmailVerificationTokenDTO;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class EmailVerificationTokenRepositoryTest extends TestCase
{
    public function testPortCreatesAndReloadsAnImmutableTokenAndDeletesByIdentity(): void
    {
        $user = $this->createTestUser();
        $repository = $this->repository();
        $expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05+00:00');
        $created = $repository->createForUser($user->getId(), 'port-token', $expiresAt);
        $this->assertInstanceOf(EmailVerificationTokenDTO::class, $created);
        $this->assertTrue($user->getId()->equals($created->userId));
        $this->assertSame('port-token', $created->token);
        $this->assertEquals($expiresAt, $created->expiresAt);
        $this->assertNull($created->usedAt);

        $this->entityManager->clear();
        $loaded = $repository->findByToken('port-token');
        $this->assertNotNull($loaded);
        $this->assertTrue($created->id->equals($loaded->id));
        $this->assertTrue($created->userId->equals($loaded->userId));
        $this->assertSame($created->token, $loaded->token);
        $this->assertEquals($expiresAt, $loaded->expiresAt);

        $entity = $this->entityManager->find(EmailVerificationTokenEntity::class, $loaded->id);
        $this->assertInstanceOf(EmailVerificationTokenEntity::class, $entity);
        $entity->markUsed();
        $this->entityManager->flush();
        $this->assertNull($loaded->usedAt, 'A previously returned snapshot cannot change with the managed entity.');
        $used = $repository->findByToken('port-token');
        $this->assertNotNull($used);
        $this->assertEquals($entity->getUsedAt(), $used->usedAt);

        $repository->delete($loaded->id);
        $this->entityManager->clear();
        $this->assertNull($repository->findByToken('port-token'));
        $repository->delete($loaded->id);
        $repository->delete(Uuid::generate());
        $this->assertNull($repository->findByToken('unknown-token'));
    }

    public function testVerificationConsumesTheTokenAndRejectsReplay(): void
    {
        $user = $this->createTestUser();
        $this->repository()->createForUser($user->getId(), 'single-use-token', new \DateTimeImmutable('+1 hour'));

        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/email/verify', ['token' => 'single-use-token']), 200);
        $this->assertNull($this->repository()->findByToken('single-use-token'));
        $verified = $this->userRepository->findByUuid($user->getId());
        $this->assertNotNull($verified);
        $this->assertTrue($verified->isEmailVerified());
        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/email/verify', ['token' => 'single-use-token']), 400);
    }

    public function testExpiredVerificationPreservesTokenAndUnverifiedUser(): void
    {
        $user = $this->createTestUser();
        $created = $this->repository()->createForUser($user->getId(), 'expired-port-token', new \DateTimeImmutable('-1 hour'));

        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/email/verify', ['token' => 'expired-port-token']), 400);
        $remaining = $this->repository()->findByToken('expired-port-token');
        $this->assertNotNull($remaining);
        $this->assertTrue($created->id->equals($remaining->id));
        $unverified = $this->userRepository->findByUuid($user->getId());
        $this->assertNotNull($unverified);
        $this->assertFalse($unverified->isEmailVerified());
    }

    public function testMissingUserCannotCreateAToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found.');
        $this->repository()->createForUser(Uuid::generate(), 'orphan-token', new \DateTimeImmutable('+1 hour'));
    }

    private function repository(): EmailVerificationTokenRepositoryInterface
    {
        return static::getContainer()->get(EmailVerificationTokenRepositoryInterface::class);
    }
}
