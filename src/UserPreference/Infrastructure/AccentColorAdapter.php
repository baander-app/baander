<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\AccentColorPortInterface;
use App\UserPreference\Domain\Repository\AccentColorRepositoryInterface;

final class AccentColorAdapter implements AccentColorPortInterface
{
    private const DEFAULT_COLOR = 'violet';

    public function __construct(
        private readonly AccentColorRepositoryInterface $repository,
    ) {
    }

    public function getAccentColor(Uuid $userId): string
    {
        return $this->repository->getAccentColor($userId) ?? self::DEFAULT_COLOR;
    }

    public function setAccentColor(Uuid $userId, string $color): void
    {
        $this->repository->setAccentColor($userId, $color);
    }
}
