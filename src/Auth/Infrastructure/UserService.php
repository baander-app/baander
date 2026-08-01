<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class UserService implements UserPortInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function save(User $user): void
    {
        $this->userRepository->save($user);
    }

    public function findByEmail(Email $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    public function findByPublicId(PublicId $publicId): ?User
    {
        return $this->userRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?User
    {
        return $this->userRepository->findByUuid($uuid);
    }

    public function existsWithEmail(Email $email): bool
    {
        return $this->userRepository->existsWithEmail($email);
    }

    public function delete(Uuid $id): void
    {
        $this->userRepository->delete($id);
    }

    public function findAll(?string $roleFilter = null, ?bool $disabledFilter = null, int $limit = 50, int $offset = 0): array
    {
        return $this->userRepository->findAll($roleFilter, $disabledFilter, $limit, $offset);
    }

    public function count(?string $roleFilter = null, ?bool $disabledFilter = null): int
    {
        return $this->userRepository->count($roleFilter, $disabledFilter);
    }
}
