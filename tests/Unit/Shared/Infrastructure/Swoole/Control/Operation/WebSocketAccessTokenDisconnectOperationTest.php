<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketAccessTokenDisconnectOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketUserDisconnectOperation;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketAccessTokenDisconnectOperationTest extends TestCase
{
    private const string USER = '0192a3b4-c5d6-7890-abcd-ef1234567890';
    private const string OTHER_USER = '0192a3b4-c5d6-7890-abcd-ef1234567891';

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is not loaded.');
        }
    }

    public function testClosesEveryConnectionOfTheAccessTokensAndNoOther(): void
    {
        $registry = WebSocketConnectionRegistry::create(16, 64);
        $registry->addConnection(7, self::USER, 0, 'token-a');
        $registry->addConnection(8, self::USER, 1, 'token-b');
        $registry->addConnection(9, self::USER, 0, 'token-c');
        $registry->addConnection(10, self::OTHER_USER, 0, 'token-d');

        $disconnected = [];
        $server = $this->createMock(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->expects(self::exactly(2))->method('disconnect')
            ->with(self::anything(), WebSocketUserDisconnectOperation::CLOSE_CODE, WebSocketUserDisconnectOperation::CLOSE_REASON)
            ->willReturnCallback(static function (int $fd) use (&$disconnected): bool {
                $disconnected[] = $fd;

                return true;
            });
        $pusher = new WebSocketPusher($registry, new JsonEncoder());
        $pusher->setServer($server);

        $operation = new WebSocketAccessTokenDisconnectOperation($registry, $pusher);

        self::assertSame('websocket.access_token.disconnect', $operation->name());
        self::assertFalse($operation->fansOut(), 'The connection table is shared, so one worker does the whole job.');
        self::assertSame(['closed' => 2], $operation->handle(['access_token_ids' => ['token-a', 'token-b']]));
        sort($disconnected);
        self::assertSame([7, 8], $disconnected);
    }

    public function testATokenWithoutConnectionsClosesNothing(): void
    {
        $registry = WebSocketConnectionRegistry::create(16, 64);
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('disconnect');
        $server->expects(self::never())->method('close');
        $pusher = new WebSocketPusher($registry, new JsonEncoder());
        $pusher->setServer($server);

        $operation = new WebSocketAccessTokenDisconnectOperation($registry, $pusher);

        self::assertSame(['closed' => 0], $operation->handle(['access_token_ids' => ['token-unknown']]));
    }

    public function testRejectsAPayloadWithoutAccessTokenIds(): void
    {
        $registry = WebSocketConnectionRegistry::create(16, 64);
        $operation = new WebSocketAccessTokenDisconnectOperation($registry, new WebSocketPusher($registry, new JsonEncoder()));

        $rejected = 0;
        foreach ([[], ['access_token_ids' => 'token-a'], ['access_token_ids' => []], ['access_token_ids' => [42]], ['access_token_ids' => ['']]] as $payload) {
            try {
                $operation->handle($payload);
                self::fail('A payload without a list of access token ids must be rejected: ' . json_encode($payload));
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(5, $rejected);
    }
}
