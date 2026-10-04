<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request;

final readonly class OffsetPagination
{
    public function __construct(public int $limit, public int $offset) {}
}
