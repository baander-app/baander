<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\RadioSession;

use App\Radio\Domain\Model\RadioSession\RadioSession;
use App\Shared\Domain\Model\Uuid;

interface RadioSessionRepositoryInterface
{
    public function find(Uuid $id): ?RadioSession;

    public function findByUserId(Uuid $userId): ?RadioSession;

    public function save(RadioSession $session): void;

    public function remove(RadioSession $session): void;
}
