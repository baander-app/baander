<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

final readonly class WatchedStateResult
{
    public function __construct(public bool $watched, public int $titles) {}
}
