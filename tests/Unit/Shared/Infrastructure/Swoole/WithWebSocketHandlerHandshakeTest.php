<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\AbstractWebSocketController;
use SwooleBundle\SwooleBundle\Server\Configurator\WithWebSocketHandler;

/**
 * The handshake hands the controller what the authenticator established: the user
 * and the access token the connection was opened with. The token id is what a later
 * revocation closes the connection by.
 */
final class WithWebSocketHandlerHandshakeTest extends TestCase
{
    private const string USER = '01900000-0000-7000-8000-000000000001';
    private const string ACCESS_TOKEN = 'access-token-id-of-the-handshake';

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is not loaded.');
        }
    }

    public function testTheUserIdAndTheAccessTokenIdReachOnOpen(): void
    {
        $controller = $this->controllerSpy();
        $authenticator = static fn (SwooleRequest $request): array => [
            'user_id' => self::USER,
            'access_token_id' => self::ACCESS_TOKEN,
        ];

        $this->handshake($controller, $authenticator, 17);

        self::assertSame([[17, self::USER, self::ACCESS_TOKEN]], $controller->opened);
    }

    public function testAnAuthenticatorAnswerWithoutTheTokenIdRejectsTheHandshake(): void
    {
        $controller = $this->controllerSpy();
        $authenticator = static fn (SwooleRequest $request): array => ['user_id' => self::USER];

        $this->handshake($controller, $authenticator, 18);

        self::assertSame([], $controller->opened, 'A connection without a token id could never be closed by revocation.');
    }

    public function testARejectedAuthenticationNeverReachesOnOpen(): void
    {
        $controller = $this->controllerSpy();
        $authenticator = static fn (SwooleRequest $request): ?array => null;

        $this->handshake($controller, $authenticator, 19);

        self::assertSame([], $controller->opened);
    }

    /**
     * Drives the handshake callback the configurator registers on the server, with a
     * request and a response detached from any connection.
     */
    private function handshake(AbstractWebSocketController $controller, callable $authenticator, int $fd): void
    {
        $handshake = null;
        $server = $this->createStub(Server::class);
        $server->method('on')->willReturnCallback(static function (string $event, callable $callback) use (&$handshake): bool {
            if ($event === 'Handshake') {
                $handshake = $callback;
            }

            return true;
        });

        $configurator = new WithWebSocketHandler(
            $controller,
            new CoWrapper(new ServicePoolContainer([]), new Swoole()),
            $authenticator,
        );
        $configurator->configure($server);
        self::assertNotNull($handshake, 'The configurator registers the handshake callback.');

        $request = new SwooleRequest();
        $request->fd = $fd;
        $request->header = ['sec-websocket-key' => base64_encode(random_bytes(16))];
        $request->get = ['token' => 'presented-token'];
        $request->server = ['request_uri' => '/api/ws'];

        // The configurator runs the handshake in a coroutine; a scheduler waits for it
        // here, so nothing is left for the event loop at shutdown.
        \Swoole\Coroutine\run(static function () use ($handshake, $request): void {
            $handshake($request, new SwooleResponse());
        });
    }

    /** @return AbstractWebSocketController&object{opened: list<array{int, string, string}>} */
    private function controllerSpy(): AbstractWebSocketController
    {
        return new class extends AbstractWebSocketController {
            /** @var list<array{int, string, string}> */
            public array $opened = [];

            public function onOpen(int $fd, string $userId, string $accessTokenId): void
            {
                $this->opened[] = [$fd, $userId, $accessTokenId];
            }

            public function onMessage(int $fd, string $data): void
            {
            }

            public function onClose(int $fd): void
            {
            }
        };
    }
}
