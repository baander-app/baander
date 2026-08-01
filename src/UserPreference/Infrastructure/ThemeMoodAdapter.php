<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\ThemeMoodPortInterface;
use App\UserPreference\Domain\Repository\ThemeMoodRepositoryInterface;

final class ThemeMoodAdapter implements ThemeMoodPortInterface
{
    private const DEFAULT_MOOD = 'dark';

    public function __construct(
        private readonly ThemeMoodRepositoryInterface $repository,
    ) {
    }

    public function getThemeMood(Uuid $userId): string
    {
        return $this->repository->getThemeMood($userId) ?? self::DEFAULT_MOOD;
    }

    public function setThemeMood(Uuid $userId, string $mood): void
    {
        $this->repository->setThemeMood($userId, $mood);
    }
}
