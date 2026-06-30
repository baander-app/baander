<?php

declare(strict_types=1);

namespace App\Session\Infrastructure;

use App\Session\Application\Port\SessionPortInterface;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Domain\Repository\ListeningSession\ListeningSessionRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SessionAdapter implements SessionPortInterface
{
    public function __construct(
        private readonly ListeningSessionRepositoryInterface $sessionRepository,
        private readonly DeviceRepositoryInterface $deviceRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getSession(Uuid $userId): ?array
    {
        $session = $this->sessionRepository->findByUserId($userId);

        return $session !== null ? $this->sessionToArray($session) : null;
    }

    public function syncSession(Uuid $userId, Uuid $deviceId, array $queue, int $currentTrackIndex, float $position, string $playbackState): array
    {
        $session = $this->sessionRepository->findByUserId($userId);

        if ($session === null) {
            throw new RuntimeException('No active session found for user.');
        }

        // Active-device sync gate: silently return current state if this device is not active
        $activeDeviceId = $session->getActiveDeviceId();
        if ($activeDeviceId !== null && !$activeDeviceId->equals($deviceId)) {
            return $this->sessionToArray($session);
        }

        $session->updatePlayback($queue, $currentTrackIndex, $position, $playbackState);
        $session->markUsed();
        $this->sessionRepository->save($session);
        $this->dispatchEvents($session);

        return $this->sessionToArray($session);
    }

    public function claimSession(Uuid $userId, Uuid $deviceId): array
    {
        $session = $this->sessionRepository->findByUserId($userId);

        if ($session === null) {
            throw new RuntimeException('No active session found for user.');
        }

        $session->claim($deviceId);
        $this->sessionRepository->save($session);
        $this->dispatchEvents($session);

        return $this->sessionToArray($session);
    }

    public function createSession(Uuid $userId, array $queue, int $currentTrackIndex, float $position): array
    {
        $existing = $this->sessionRepository->findByUserId($userId);
        if ($existing !== null) {
            $this->sessionRepository->remove($existing);
        }

        $session = ListeningSession::create($userId, $queue, $currentTrackIndex, $position);
        $this->sessionRepository->save($session);
        $this->dispatchEvents($session);

        return $this->sessionToArray($session);
    }

    public function registerDevice(Uuid $userId, Uuid $deviceId, string $name): void
    {
        $device = $this->deviceRepository->findByUserAndDevice($userId, $deviceId);

        if ($device === null) {
            $device = Device::create($userId, $deviceId, $name);
        } else {
            $device->touch();
            if ($name !== '' && $name !== $device->getName()) {
                $device->rename($name);
            }
        }

        $this->deviceRepository->save($device);
    }

    public function getDevices(Uuid $userId): array
    {
        $devices = $this->deviceRepository->findByUserId($userId);

        return array_map(function (Device $device): array {
            return [
                'id' => $device->getId()->toString(),
                'deviceId' => $device->getDeviceId()->toString(),
                'name' => $device->getName(),
                'lastUsedAt' => $device->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $devices);
    }

    public function renameDevice(Uuid $userId, Uuid $deviceId, string $name): void
    {
        $device = $this->deviceRepository->findByUserAndDevice($userId, $deviceId);

        if ($device === null) {
            throw new RuntimeException('Device not found.');
        }

        $device->rename($name);
        $this->deviceRepository->save($device);
    }

    public function forgetDevice(Uuid $userId, Uuid $deviceId): void
    {
        $device = $this->deviceRepository->findByUserAndDevice($userId, $deviceId);

        if ($device !== null) {
            $this->deviceRepository->remove($device);
        }
    }

    /**
     * Drain and dispatch domain events from a listening session.
     */
    private function dispatchEvents(ListeningSession $session): void
    {
        $events = $session->drainPendingEvents();
        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionToArray(ListeningSession $session): array
    {
        return [
            'id' => $session->getId()->toString(),
            'userId' => $session->getUserId()->toString(),
            'activeDeviceId' => $session->getActiveDeviceId()?->toString(),
            'queue' => $session->getQueue(),
            'currentTrackIndex' => $session->getCurrentTrackIndex(),
            'position' => $session->getPosition(),
            'playbackState' => $session->getPlaybackState(),
            'createdAt' => $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $session->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
