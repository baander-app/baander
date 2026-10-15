<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\WebSocketTicketRedeemerInterface;
use App\Shared\Infrastructure\Security\WsTicketAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use SwooleBundle\SwooleBundle\Server\Exception\AuthenticationUnavailableException;

final class WsTicketAuthenticatorTest extends TestCase
{
    private const string TICKET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testSuccessfulRedemptionRetainsBothConnectionIdentifiers(): void
    {
        $redeemer = $this->createMock(WebSocketTicketRedeemerInterface::class);
        $redeemer->expects(self::once())->method('redeem')
            ->with(self::TICKET, 'https://baander.app', '/api/ws')
            ->willReturn(new WebSocketIdentity('user-identity', 'authorizing-token'));

        self::assertSame(['user_id' => 'user-identity', 'access_token_id' => 'authorizing-token'], (new WsTicketAuthenticator($redeemer))->authenticate($this->request()));
    }

    public function testRejectedRedemptionCannotAuthenticate(): void
    {
        $redeemer = $this->createStub(WebSocketTicketRedeemerInterface::class);
        $redeemer->method('redeem')->willReturn(null);

        self::assertNull((new WsTicketAuthenticator($redeemer))->authenticate($this->request()));
    }

    public function testUnavailableRedemptionUsesTheBundlesGenericFailure(): void
    {
        $redeemer = $this->createStub(WebSocketTicketRedeemerInterface::class);
        $redeemer->method('redeem')->willThrowException(new WebSocketTicketUnavailableException('Private store diagnostics'));

        $this->expectException(AuthenticationUnavailableException::class);
        $this->expectExceptionMessage('WebSocket authentication is unavailable.');
        (new WsTicketAuthenticator($redeemer))->authenticate($this->request());
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsNeverReachTheRedeemer(array $server, array $headers): void
    {
        $redeemer = $this->createMock(WebSocketTicketRedeemerInterface::class);
        $redeemer->expects(self::never())->method('redeem');

        self::assertNull((new WsTicketAuthenticator($redeemer))->authenticate($this->request($server, $headers)));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        foreach ([
            'absent query' => null,
            'empty ticket' => 'ticket=',
            'legacy token' => 'token=' . self::TICKET,
            'duplicate' => 'ticket=' . self::TICKET . '&ticket=' . self::TICKET,
            'encoded alias' => '%74icket=' . self::TICKET,
            'array' => 'ticket[]=' . self::TICKET,
            'extra parameter' => 'ticket=' . self::TICKET . '&token=private-token',
            'short ticket' => 'ticket=abcdef',
            'uppercase ticket' => 'ticket=' . strtoupper(self::TICKET),
            'encoded value' => 'ticket=%30' . substr(self::TICKET, 1),
            'trailing newline' => 'ticket=' . self::TICKET . "\n",
        ] as $name => $query) {
            yield $name => [['query_string' => $query], []];
        }
        yield 'wrong method' => [['request_method' => 'POST'], []];
        yield 'wrong path' => [['request_uri' => '/api/ws/'], []];
        yield 'missing origin' => [[], ['origin' => null]];
        yield 'null origin' => [[], ['origin' => 'null']];
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    private function request(array $server = [], array $headers = []): Request
    {
        $request = new Request();
        $request->server = array_replace([
            'request_method' => 'GET',
            'request_uri' => '/api/ws',
            'query_string' => 'ticket=' . self::TICKET,
        ], $server);
        $request->header = array_replace(['origin' => 'https://baander.app'], $headers);
        // Parsed query fields cannot revive a rejected raw query or legacy credential.
        $request->get = ['ticket' => self::TICKET, 'token' => 'unused-token'];

        return $request;
    }
}
