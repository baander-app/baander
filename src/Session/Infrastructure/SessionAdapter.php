<?php

declare(strict_types=1);

namespace App\Session\Infrastructure;

use App\Session\Application\Exception\DeviceNotFound;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Domain\Repository\ListeningSession\ListeningSessionRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Session\Application\Port\SessionUserPublicIdentityInterface;
use RuntimeException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SessionAdapter implements SessionPortInterface
{
    public function __construct(
        private readonly ListeningSessionRepositoryInterface $sessionRepository,
        private readonly DeviceRepositoryInterface $deviceRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SessionUserPublicIdentityInterface $userIdentifiers,
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

        $queue = $this->publicQueue($queue);
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

        $this->registerDevice($userId, $deviceId, '');
        $session->claim($deviceId);
        $this->sessionRepository->save($session);
        $this->dispatchEvents($session);

        return $this->sessionToArray($session);
    }

    public function createSession(Uuid $userId, array $queue, int $currentTrackIndex, float $position): array
    {
        $queue = $this->publicQueue($queue);
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
                'publicId' => $device->getPublicId()->toString(),
                'deviceId' => $device->getDeviceId()->toString(),
                'name' => $device->getName(),
                'lastUsedAt' => $device->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $devices);
    }

    public function findDeviceByPublicId(PublicId $publicId, Uuid $userId): ?Device
    {
        return $this->deviceRepository->findByUserAndPublicId($userId, $publicId);
    }

    public function devicePublicId(Uuid $userId, Uuid $browserToken): ?PublicId
    {
        return $this->deviceRepository->findByUserAndDevice($userId, $browserToken)?->getPublicId();
    }

    public function renameDevice(Uuid $userId, PublicId $deviceId, string $name): void
    {
        $device = $this->deviceRepository->findByUserAndPublicId($userId, $deviceId);

        if ($device === null) {
            throw new DeviceNotFound('Device not found.');
        }

        $device->rename($name);
        $this->deviceRepository->save($device);
    }

    public function forgetDevice(Uuid $userId, PublicId $deviceId): void
    {
        $device = $this->deviceRepository->findByUserAndPublicId($userId, $deviceId);

        if ($device !== null) {
            $session = $this->sessionRepository->findByUserId($userId);
            if ($session !== null && $session->getActiveDeviceId()?->equals($device->getDeviceId())) {
                $session->releaseDevice($device->getDeviceId());
                $this->sessionRepository->save($session);
            }
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
     * @param array<array-key, mixed> $queue
     * @return list<string>
     */
    private function publicQueue(array $queue): array
    {
        return array_values(array_map(static function (mixed $id): string {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Queue entries must be song public IDs.');
            }

            return PublicId::fromString($id)->toString();
        }, $queue));
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionToArray(ListeningSession $session): array
    {
        return [
            'publicId' => $session->getPublicId()->toString(),
            'userId' => $this->userIdentifiers->publicIdByUserId($session->getUserId())->toString(),
            'activeDeviceId' => $session->getActiveDeviceId() !== null
                ? ($this->devicePublicId($session->getUserId(), $session->getActiveDeviceId())
                    ?? throw new RuntimeException('The active device is not registered.'))->toString()
                : null,
            'queue' => $this->publicQueue($session->getQueue()),
            'currentTrackIndex' => $session->getCurrentTrackIndex(),
            'position' => $session->getPosition(),
            'playbackState' => $session->getPlaybackState(),
            'createdAt' => $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $session->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
