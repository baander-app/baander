<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Swoole;

use App\Session\Domain\Event\SessionClaimed;
use App\Session\Domain\Event\SessionUpdated;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SessionEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebSocketPusher $webSocketPusher,
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
                    'deviceId' => $event->getDeviceId()->toString(),
                    'userId' => $event->getUserId()->toString(),
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
                    'userId' => $event->getUserId()->toString(),
                    'queue' => $event->getQueue(),
                ],
            ],
        );
    }
}
