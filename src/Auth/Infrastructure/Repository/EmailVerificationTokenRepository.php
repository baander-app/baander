<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class EmailVerificationTokenRepository implements EmailVerificationTokenRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function createForUser(Uuid $userId, string $token, \DateTimeImmutable $expiresAt): EmailVerificationTokenEntity
    {
        $user = $this->entityManager->find(UserEntity::class, $userId);

        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $entity = new EmailVerificationTokenEntity($user, $token, $expiresAt);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    public function findByToken(string $token): ?EmailVerificationTokenEntity
    {
        return $this->entityManager
            ->getRepository(EmailVerificationTokenEntity::class)
            ->findOneBy(['token' => $token]);
    }

    public function delete(EmailVerificationTokenEntity $token): void
    {
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }
}
