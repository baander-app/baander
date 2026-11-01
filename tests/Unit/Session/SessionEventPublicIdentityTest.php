<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Domain\Event\SessionClaimed;
use App\Session\Domain\Event\SessionUpdated;
use App\Session\Infrastructure\Swoole\SessionEventSubscriber;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SessionEventPublicIdentityTest extends TestCase
{
    public function testPublicMessagesCarryTheCompleteSnapshotWithoutPrivateEndpointAuthority(): void
    {
        $owner = new Uuid();
        $browser = new Uuid();
        $snapshot = ['schemaVersion' => 1, 'publicId' => new PublicId()->toString(), 'revision' => 5, 'media' => null,
            'queue' => [new PublicId()->toString()], 'currentTrackIndex' => 0, 'position' => 42.5, 'playbackState' => 'paused',
            'desiredState' => 'paused', 'observedState' => 'unknown', 'selectedDestinationId' => null, 'activeDestinationId' => null,
            'activeIncarnationId' => null, 'transfer' => null, 'destinations' => [], 'commands' => []];
        $playback = $this->createMock(PlaybackCoordinationInterface::class);
        $playback->expects(self::exactly(2))->method('execute')->with($owner, PlaybackOperation::Snapshot, [])->willReturn($snapshot);
        $messages = [];
        $encoder = $this->createMock(JsonEncoder::class);
        $encoder->expects(self::exactly(2))->method('encode')->willReturnCallback(static function (mixed $message) use (&$messages): string {
            $messages[] = $message;
            return json_encode($message, JSON_THROW_ON_ERROR);
        });
        $subscriber = new SessionEventSubscriber(new WebSocketPusher(WebSocketConnectionRegistry::create(64, 64), $encoder), $playback);
        $subscriber->onSessionClaimed(new SessionClaimed($owner, $browser, new \DateTimeImmutable()));
        $subscriber->onSessionUpdated(new SessionUpdated($owner, $browser, $snapshot['queue'], new \DateTimeImmutable()));
        self::assertSame([['type' => 'session.snapshot', 'data' => $snapshot], ['type' => 'session.snapshot', 'data' => $snapshot]], $messages);
        self::assertStringNotContainsString($owner->toString(), json_encode($messages, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($browser->toString(), json_encode($messages, JSON_THROW_ON_ERROR));
    }
}
