<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class SidebarConfigState
{
    /**
     * @param SidebarItem[] $items
     */
    public function __construct(
        public Uuid $id,
        public Uuid $userId,
        public string $mediaType,
        public array $items,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
