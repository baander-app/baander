<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository;

use App\Auth\Domain\Model\LoginBlock;
use App\Shared\Domain\Model\Uuid;

interface LoginBlockRepositoryInterface
{
    public function save(LoginBlock $block): void;

    /**
     * @return LoginBlock[]
     */
    public function findRecent(int $limit = 50, int $offset = 0): array;

    public function countRecent(): int;

    /** @return bool whether a block had the UUID and was removed */
    public function deleteByUuid(Uuid $uuid): bool;

    public function deleteAll(): void;
}
