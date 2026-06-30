<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Radio\Domain\Model\RadioSession\RadioSession;
use App\Radio\Domain\Repository\RadioSession\RadioSessionRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;

final class RadioSessionService implements RadioSessionPortInterface
{
    public function __construct(
        private readonly RadioSessionRepositoryInterface $repository,
    ) {
    }

    public function getSession(Uuid $userId): ?array
    {
        $session = $this->repository->findByUserId($userId);

        return $session !== null ? $this->sessionToArray($session) : null;
    }

    public function startRadio(Uuid $userId, Uuid $stationId, string $streamUrl): array
    {
        $session = $this->repository->findByUserId($userId);

        if ($session === null) {
            $session = RadioSession::create($userId);
        }

        $session->start($stationId, $streamUrl);
        $session->drainPendingEvents(); // events handled internally for now
        $this->repository->save($session);

        return $this->sessionToArray($session);
    }

    public function stopRadio(Uuid $userId): array
    {
        $session = $this->repository->findByUserId($userId);

        if ($session === null) {
            throw new RuntimeException('No active radio session.');
        }

        $session->stop();
        $session->drainPendingEvents();
        $this->repository->save($session);

        return $this->sessionToArray($session);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionToArray(RadioSession $session): array
    {
        return [
            'id' => $session->getId()->toString(),
            'userId' => $session->getUserId()->toString(),
            'state' => $session->getState(),
            'activeStationId' => $session->getActiveStationId()?->toString(),
            'activeStreamUrl' => $session->getActiveStreamUrl(),
            'createdAt' => $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
