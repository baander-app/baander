<?php

declare(strict_types=1);

namespace App\Session\Domain\Repository\ListeningSession;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Shared\Domain\Model\Uuid;

interface ListeningSessionRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?ListeningSession;

    public function save(ListeningSession $session): void;

    public function remove(ListeningSession $session): void;
}
