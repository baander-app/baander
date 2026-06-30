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

    public function deleteByUuid(Uuid $uuid): void;

    public function deleteAll(): void;
}
