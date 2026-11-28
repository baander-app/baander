<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

final readonly class VideoProgressResult
{
    public function __construct(public bool $recorded) {}
}
