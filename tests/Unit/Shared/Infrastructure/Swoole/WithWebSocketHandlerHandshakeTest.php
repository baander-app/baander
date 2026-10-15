<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\AbstractWebSocketController;
use SwooleBundle\SwooleBundle\Server\Configurator\WithWebSocketHandler;
use SwooleBundle\SwooleBundle\Server\Exception\AuthenticationUnavailableException;

final class WithWebSocketHandlerHandshakeTest extends TestCase
{
    private const string TICKET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const array IDENTITY = ['user_id' => '01900000-0000-7000-8000-000000000001', 'access_token_id' => 'authorizing-access-token'];

    public function testSuccessfulRedemptionReachesOnOpenWithBothIdentifiers(): void
    {
        $reply = $this->handshake(static fn (): array => self::IDENTITY);

        self::assertSame(101, $reply['status']);
        self::assertSame([[17, self::IDENTITY['user_id'], self::IDENTITY['access_token_id']]], $reply['opened']);
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $reply['headers']['Sec-WebSocket-Accept']);
    }

    /** @param array<string, mixed> $server @param array<string, mixed> $headers */
    #[DataProvider('invalidHandshakes')]
    public function testInvalidWireRequestsCannotConsumeTheTicket(array $server, array $headers): void
    {
        $calls = 0;
        $authenticator = static function () use (&$calls): array {
            ++$calls;

            return self::IDENTITY;
        };
        $reply = $this->handshake($authenticator, $server, $headers);

        self::assertSame(400, $reply['status']);
        self::assertSame([], $reply['opened']);
        self::assertSame(0, $calls);
        self::assertSame(101, $this->handshake($authenticator)['status'], 'The rejected request must leave the ticket usable by a valid request.');
        self::assertSame(1, $calls);
    }

    /** @param list<string>|null $origins */
    #[DataProvider('invalidOrigins')]
    public function testOriginRejectionPrecedesRedemption(?array $origins, mixed $origin): void
    {
        $calls = 0;
        $reply = $this->handshake(static function () use (&$calls): array {
            ++$calls;

            return self::IDENTITY;
        }, headers: ['origin' => $origin], origins: $origins);

        self::assertSame(403, $reply['status']);
        self::assertSame(0, $calls);
        self::assertSame([], $reply['opened']);
    }

    public function testMissingTokenIdentityCannotOpenAConnection(): void
    {
        $reply = $this->handshake(static fn (): array => ['user_id' => self::IDENTITY['user_id']]);

        self::assertSame(401, $reply['status']);
        self::assertSame([], $reply['opened']);
    }

    public function testObjectAuthenticatorsRemainSupported(): void
    {
        $authenticator = new class(self::IDENTITY) {
            public function __construct(private array $identity) {}
            public function authenticate(Request $request): array { return $this->identity; }
        };
        self::assertSame(101, $this->handshake($authenticator)['status']);
    }

    public function testUnavailableAuthenticationReturnsARetryableFailure(): void
    {
        $reply = $this->handshake(static function (): never {
            throw new AuthenticationUnavailableException('Private diagnostics with ticket=' . self::TICKET);
        });

        self::assertSame(503, $reply['status']);
        self::assertSame('1', $reply['headers']['Retry-After']);
        self::assertSame('no-store', $reply['headers']['Cache-Control']);
        self::assertSame('Authentication unavailable', $reply['body']);
        self::assertSame([], $reply['opened']);
    }

    public function testUnexpectedAuthenticationErrorsNeverLogTheSecretOrExceptionMessage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-ws-errors-');
        self::assertNotFalse($path);
        $previous = ini_set('error_log', $path);
        try {
            $reply = $this->handshake(static function (): never {
                throw new \RuntimeException('Private ticket=' . self::TICKET);
            });
            $log = file_get_contents($path);
            self::assertIsString($log);
            self::assertStringContainsString('Authentication or connection registration failed.', $log);
            self::assertStringNotContainsString(self::TICKET, $log);
            self::assertStringNotContainsString('Private', $log);
            self::assertSame(500, $reply['status']);
            self::assertSame('Internal server error', $reply['body']);
            self::assertSame([], $reply['opened']);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($path);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function invalidHandshakes(): iterable
    {
        yield 'wrong method' => [['request_method' => 'POST'], []];
        yield 'wrong path' => [['request_uri' => '/api/ws/'], []];
        yield 'encoded path' => [['request_uri' => '/api/%77s'], []];
        foreach (['', 'token=stolen-token', 'ticket=' . self::TICKET . '&ticket=' . self::TICKET,
            '%74icket=' . self::TICKET, 'ticket[]=' . self::TICKET, 'ticket=' . self::TICKET . '&extra=1',
            'ticket=' . strtoupper(self::TICKET), 'ticket=%30' . substr(self::TICKET, 1)] as $query) {
            yield 'query ' . $query => [['query_string' => $query], []];
        }
        yield 'missing key' => [[], ['sec-websocket-key' => null]];
        yield 'invalid base64' => [[], ['sec-websocket-key' => 'not-base64']];
        yield 'wrong key length' => [[], ['sec-websocket-key' => base64_encode('too short')]];
        yield 'noncanonical key' => [[], ['sec-websocket-key' => ' ' . base64_encode(str_repeat('a', 16))]];
        yield 'missing version' => [[], ['sec-websocket-version' => null]];
        yield 'old version' => [[], ['sec-websocket-version' => '12']];
        yield 'missing upgrade' => [[], ['upgrade' => null]];
        yield 'wrong upgrade' => [[], ['upgrade' => 'h2c']];
        yield 'missing connection upgrade' => [[], ['connection' => 'keep-alive']];
        yield 'empty connection token' => [[], ['connection' => 'Upgrade,']];
        yield 'malformed connection token' => [[], ['connection' => 'Upgrade, not a token']];
        yield 'connection control bytes' => [[], ['connection' => "Upgrade\r\nInjected: value"]];
    }

    /** @return iterable<string, array{list<string>|null, mixed}> */
    public static function invalidOrigins(): iterable
    {
        yield 'unconfigured allowlist' => [null, 'https://baander.app'];
        yield 'empty allowlist' => [[], 'https://baander.app'];
        foreach ([null, '', 'null', 'https://foreign.baander.app', 'https://baander.app/',
            'https://baander.app:443', 'https://BAANDER.APP', 'https://baander.app.evil.baander.app',
            'https://baander.app https://foreign.baander.app', ['https://baander.app']] as $origin) {
            yield 'origin ' . json_encode($origin, JSON_THROW_ON_ERROR) => [['https://baander.app'], $origin];
        }
        yield 'malformed configured origin' => [['https://baander.app/'], 'https://baander.app/'];
    }

    /**
     * @param array<string, mixed> $serverFields
     * @param array<string, mixed> $headers
     * @param list<string>|null $origins
     * @return array{status: int|null, body: string|null, headers: array<string, array|string>, opened: list<array{int, string, string}>}
     */
    private function handshake(mixed $authenticator, array $serverFields = [], array $headers = [], ?array $origins = ['https://baander.app']): array
    {
        $controller = new class extends AbstractWebSocketController {
            /** @var list<array{int, string, string}> */
            public array $opened = [];
            public function onOpen(int $fd, string $userId, string $accessTokenId): void { $this->opened[] = [$fd, $userId, $accessTokenId]; }
            public function onMessage(int $fd, string $data): void {}
            public function onClose(int $fd): void {}
        };
        $callback = null;
        $server = $this->createStub(Server::class);
        $server->method('on')->willReturnCallback(static function (string $event, callable $handler) use (&$callback): bool {
            if ($event === 'Handshake') {
                $callback = $handler;
            }

            return true;
        });
        (new WithWebSocketHandler($controller, new CoWrapper(new ServicePoolContainer([]), new Swoole()), $authenticator, $origins))->configure($server);
        self::assertNotNull($callback);
        $request = new Request();
        $request->fd = 17;
        $request->server = array_replace(['request_method' => 'GET', 'request_uri' => '/api/ws', 'query_string' => 'ticket=' . self::TICKET], $serverFields);
        $request->header = array_replace([
            'origin' => 'https://baander.app', 'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13', 'upgrade' => 'websocket', 'connection' => 'keep-alive, Upgrade',
        ], $headers);
        $status = null;
        $body = null;
        $responseHeaders = [];
        $response = $this->createStub(Response::class);
        $response->method('status')->willReturnCallback(static function (int $code) use (&$status): bool { $status = $code; return true; });
        $response->method('end')->willReturnCallback(static function (?string $content = null) use (&$body): bool { $body = $content; return true; });
        $response->method('header')->willReturnCallback(static function (string $name, array|string $value) use (&$responseHeaders): bool { $responseHeaders[$name] = $value; return true; });
        \Swoole\Coroutine\run(static function () use ($callback, $request, $response): void { $callback($request, $response); });

        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders, 'opened' => $controller->opened];
    }
}
