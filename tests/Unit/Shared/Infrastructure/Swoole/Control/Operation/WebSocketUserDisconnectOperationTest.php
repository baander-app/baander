<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketUserDisconnectOperation;
use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketUserDisconnectOperationTest extends TestCase
{
    private const string USER = '0192a3b4-c5d6-7890-abcd-ef1234567890';

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is not loaded.');
        }
    }

    public function testClosesTheUsersConnectionsAndVoidsItsReconnectionTokens(): void
    {
        $registry = WebSocketConnectionRegistry::create(16, 64);
        $registry->addConnection(7, self::USER, 0);
        $registry->addConnection(8, self::USER, 1);
        $tokens = ReconnectionTokenService::create(16);
        $token = $tokens->generate(self::USER);

        $server = $this->createMock(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->expects(self::exactly(2))->method('disconnect')
            ->with(self::anything(), WebSocketUserDisconnectOperation::CLOSE_CODE, WebSocketUserDisconnectOperation::CLOSE_REASON)
            ->willReturn(true);
        $pusher = new WebSocketPusher($registry, new JsonEncoder());
        $pusher->setServer($server);

        $operation = new WebSocketUserDisconnectOperation($pusher, $tokens);

        self::assertSame('websocket.user.disconnect', $operation->name());
        self::assertFalse($operation->fansOut(), 'The connection and token tables are shared, so one worker does the whole job.');
        self::assertSame(['closed' => 2, 'reconnect_tokens_revoked' => 1], $operation->handle(['user_id' => self::USER]));
        self::assertNull($tokens->consume($token));
    }

    public function testRejectsAPayloadWithoutAUserUuid(): void
    {
        $operation = new WebSocketUserDisconnectOperation(
            new WebSocketPusher(WebSocketConnectionRegistry::create(16, 64), new JsonEncoder()),
            ReconnectionTokenService::create(16),
        );

        $rejected = 0;
        foreach ([[], ['user_id' => 42], ['user_id' => 'not-a-uuid']] as $payload) {
            try {
                $operation->handle($payload);
                self::fail('A payload without a user UUID must be rejected: ' . json_encode($payload));
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(3, $rejected);
    }
}
