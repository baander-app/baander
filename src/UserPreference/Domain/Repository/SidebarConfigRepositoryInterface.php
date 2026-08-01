<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\SidebarConfig;

interface SidebarConfigRepositoryInterface
{
    public function save(SidebarConfig $config): void;

    public function findByUserAndMediaType(Uuid $userId, string $mediaType): ?SidebarConfig;

    public function delete(SidebarConfig $config): void;
}
