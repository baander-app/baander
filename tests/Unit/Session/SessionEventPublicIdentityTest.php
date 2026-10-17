<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\Application\Port\SessionUserPublicIdentityInterface;
use App\Session\Application\Port\SessionPortInterface;
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
    public function testPublicMessagesProjectRelationshipsAndKeepQueuePublicIds(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('The real connection registry requires Swoole.');
        }
        $owner = new Uuid();
        $browserToken = new Uuid();
        $userPublicId = new PublicId();
        $devicePublicId = new PublicId();
        $songPublicId = new PublicId();
        $sessions = $this->createMock(SessionPortInterface::class);
        $sessions->expects(self::once())->method('devicePublicId')->with($owner, $browserToken)->willReturn($devicePublicId);
        $users = $this->createMock(SessionUserPublicIdentityInterface::class);
        $users->expects(self::exactly(2))->method('publicIdByUserId')->with($owner)->willReturn($userPublicId);
        $messages = [];
        $encoder = $this->createMock(JsonEncoder::class);
        $encoder->expects(self::exactly(2))->method('encode')->willReturnCallback(static function (mixed $message) use (&$messages): string {
            $messages[] = $message;

            return json_encode($message, JSON_THROW_ON_ERROR);
        });
        $subscriber = new SessionEventSubscriber(new WebSocketPusher(WebSocketConnectionRegistry::create(64, 64), $encoder), $sessions, $users);

        $subscriber->onSessionClaimed(new SessionClaimed($owner, $browserToken, new \DateTimeImmutable()));
        $subscriber->onSessionUpdated(new SessionUpdated($owner, $browserToken, [$songPublicId->toString()], new \DateTimeImmutable()));

        self::assertSame([
            ['type' => 'session.claimed', 'data' => ['deviceId' => $devicePublicId->toString(), 'userId' => $userPublicId->toString()]],
            ['type' => 'session.state', 'data' => ['userId' => $userPublicId->toString(), 'queue' => [$songPublicId->toString()]]],
        ], $messages);
        $json = json_encode($messages, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($owner->toString(), $json);
        self::assertStringNotContainsString($browserToken->toString(), $json);
    }
}
