<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\LoginBlock;

/** A page of login blocks, newest first, and the number of blocks across all pages. */
final readonly class LoginBlockPage
{
    /**
     * @param list<LoginBlock> $blocks
     */
    public function __construct(
        public array $blocks,
        public int $total,
    ) {
    }
}
