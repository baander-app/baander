<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Swoole;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Domain\Event\SessionClaimed;
use App\Session\Domain\Event\SessionCreated;
use App\Session\Domain\Event\SessionUpdated;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class SessionEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private WebSocketPusher $pusher, private PlaybackCoordinationInterface $playback) {}

    public static function getSubscribedEvents(): array
    {
        return [SessionCreated::class => 'onSessionCreated', SessionClaimed::class => 'onSessionClaimed', SessionUpdated::class => 'onSessionUpdated'];
    }

    public function onSessionCreated(SessionCreated $event): void
    {
        $this->publish($event->getUserId());
    }
    public function onSessionClaimed(SessionClaimed $event): void
    {
        $this->publish($event->getUserId());
    }
    public function onSessionUpdated(SessionUpdated $event): void
    {
        $this->publish($event->getUserId());
    }

    private function publish(Uuid $owner): void
    {
        $this->pusher->push($owner->toString(), ['type' => 'session.snapshot', 'data' => $this->playback->execute($owner, PlaybackOperation::Snapshot, [])]);
    }
}
