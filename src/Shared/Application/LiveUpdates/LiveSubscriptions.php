<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use App\Shared\Domain\Model\Uuid;

interface LiveSubscriptions
{
    public function subscribe(int $fd, Uuid $principal): void;

    public function unsubscribe(int $fd): void;
}
