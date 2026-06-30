<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface UserPortInterface
{
    public function save(User $user): void;

    public function delete(Uuid $id): void;

    public function findByEmail(Email $email): ?User;

    public function findByPublicId(PublicId $publicId): ?User;

    public function findByUuid(Uuid $uuid): ?User;

    public function existsWithEmail(Email $email): bool;

    /**
     * @return list<User>
     */
    public function findAll(?string $roleFilter = null, ?bool $disabledFilter = null, int $limit = 50, int $offset = 0): array;

    public function count(?string $roleFilter = null, ?bool $disabledFilter = null): int;
}
