<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\PasswordResetTokenEntity;
use Doctrine\ORM\EntityManagerInterface;

final class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(string $email, string $token): void
    {
        $existingToken = $this->entityManager
            ->getRepository(PasswordResetTokenEntity::class)
            ->findOneBy(['email' => $email]);

        if ($existingToken !== null) {
            $existingToken->setToken($token);
        } else {
            $entity = new PasswordResetTokenEntity($email, $token);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function findByEmail(string $email): ?string
    {
        $entity = $this->entityManager
            ->getRepository(PasswordResetTokenEntity::class)
            ->findOneBy(['email' => $email]);

        if ($entity === null) {
            return null;
        }

        return $entity->getToken();
    }
}
