<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\SidebarConfig;

interface SidebarConfigPortInterface
{
    public function getConfig(Uuid $userId, string $mediaType): ?SidebarConfig;

    public function getConfigOrDefault(Uuid $userId, string $mediaType): SidebarConfig;

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    public function updateConfig(Uuid $userId, string $mediaType, array $sections): SidebarConfig;

    public function deleteConfig(Uuid $userId, string $mediaType): void;
}
