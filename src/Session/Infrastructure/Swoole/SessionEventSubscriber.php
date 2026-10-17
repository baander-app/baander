<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Swoole;

use App\Session\Domain\Event\SessionClaimed;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Application\Port\SessionUserPublicIdentityInterface;
use App\Session\Domain\Event\SessionUpdated;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Domain\Model\PublicId;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SessionEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebSocketPusher $webSocketPusher,
        private readonly SessionPortInterface $sessions,
        private readonly SessionUserPublicIdentityInterface $userIdentifiers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SessionClaimed::class => 'onSessionClaimed',
            SessionUpdated::class => 'onSessionUpdated',
        ];
    }

    public function onSessionClaimed(SessionClaimed $event): void
    {
        $this->webSocketPusher->push(
            $event->getUserId()->toString(),
            [
                'type' => 'session.claimed',
                'data' => [
                    'deviceId' => ($this->sessions->devicePublicId($event->getUserId(), $event->getDeviceId())
                        ?? throw new \RuntimeException('The claimed device is not registered.'))->toString(),
                    'userId' => $this->userIdentifiers->publicIdByUserId($event->getUserId())->toString(),
                ],
            ],
        );
    }

    public function onSessionUpdated(SessionUpdated $event): void
    {
        $this->webSocketPusher->push(
            $event->getUserId()->toString(),
            [
                'type' => 'session.state',
                'data' => [
                    'userId' => $this->userIdentifiers->publicIdByUserId($event->getUserId())->toString(),
                    'queue' => array_values(array_map(static function (mixed $id): string {
                        if (!is_string($id)) {
                            throw new \InvalidArgumentException('Queue entries must be song public IDs.');
                        }

                        return PublicId::fromString($id)->toString();
                    }, $event->getQueue())),
                ],
            ],
        );
    }
}
