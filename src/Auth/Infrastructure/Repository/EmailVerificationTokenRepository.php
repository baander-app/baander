<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\DTO\EmailVerificationTokenDTO;
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

    public function createForUser(Uuid $userId, string $token, \DateTimeImmutable $expiresAt): EmailVerificationTokenDTO
    {
        $user = $this->entityManager->find(UserEntity::class, $userId);

        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $entity = new EmailVerificationTokenEntity($user, $token, $expiresAt);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toDTO($entity);
    }

    public function findByToken(string $token): ?EmailVerificationTokenDTO
    {
        $entity = $this->entityManager
            ->getRepository(EmailVerificationTokenEntity::class)
            ->findOneBy(['token' => $token]);

        return $entity === null ? null : $this->toDTO($entity);
    }

    public function delete(Uuid $tokenId): void
    {
        $token = $this->entityManager->find(EmailVerificationTokenEntity::class, $tokenId);
        if ($token === null) {
            return;
        }
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }

    private function toDTO(EmailVerificationTokenEntity $entity): EmailVerificationTokenDTO
    {
        return new EmailVerificationTokenDTO(
            id: $entity->getId(),
            userId: $entity->getUser()->getId(),
            token: $entity->getToken(),
            expiresAt: $entity->getExpiresAt(),
            usedAt: $entity->getUsedAt(),
        );
    }
}
