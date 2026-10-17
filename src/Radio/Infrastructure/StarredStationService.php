<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\StarredStationPortInterface;
use App\Radio\Domain\Repository\RadioStation\RadioStationRepositoryInterface;
use App\Radio\Domain\Model\StarredStation\StarredStation;
use App\Radio\Domain\Repository\StarredStation\StarredStationRepositoryInterface;
use App\Radio\Application\Port\RadioPublicIdentityInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;

final class StarredStationService implements StarredStationPortInterface
{
    public function __construct(
        private readonly StarredStationRepositoryInterface $repository,
        private readonly RadioPublicIdentityInterface $identities,
        private readonly RadioStationRepositoryInterface $stations,
    ) {
    }

    public function listStarred(Uuid $userId): array
    {
        $starred = $this->repository->findByUserId($userId);

        return array_map($this->starredToArray(...), $starred);
    }

    public function star(Uuid $userId, Uuid $stationId): array
    {
        $existing = $this->repository->findByUserIdAndStationId($userId, $stationId);

        if ($existing !== null) {
            throw new RuntimeException('Station already starred.');
        }

        $starred = StarredStation::create($userId, $stationId);
        $this->repository->save($starred);

        return $this->starredToArray($starred);
    }

    public function unstar(Uuid $userId, Uuid $stationId): void
    {
        $starred = $this->repository->findByUserIdAndStationId($userId, $stationId);

        if ($starred === null) {
            throw new RuntimeException('Station not starred.');
        }

        $this->repository->remove($starred);
    }

    /**
     * @return array<string, mixed>
     */
    private function starredToArray(StarredStation $starred): array
    {
        return [
            'userId' => $this->identities->userPublicId($starred->getUserId())->toString(),
            'stationId' => ($this->stations->find($starred->getStationId())
                ?? throw new NotFoundException('Radio station not found.'))->getPublicId()->toString(),
            'starredAt' => $starred->getStarredAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
