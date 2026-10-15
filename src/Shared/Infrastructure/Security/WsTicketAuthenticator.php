<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\WebSocketTicketRedeemerInterface;
use Swoole\Http\Request;
use SwooleBundle\SwooleBundle\Server\Exception\AuthenticationUnavailableException;

final readonly class WsTicketAuthenticator
{
    public function __construct(private WebSocketTicketRedeemerInterface $redeemer)
    {
    }

    /** @return array{user_id: string, access_token_id: string}|null */
    public function authenticate(Request $request): ?array
    {
        $query = $request->server['query_string'] ?? null;
        $origin = $request->header['origin'] ?? null;
        $path = $request->server['request_uri'] ?? null;
        if (($request->server['request_method'] ?? null) !== 'GET' || $path !== '/api/ws'
            || !is_string($origin) || $origin === '' || $origin === 'null'
            || !is_string($query) || preg_match('/\Aticket=([a-f0-9]{64})\z/D', $query, $match) !== 1) {
            return null;
        }

        try {
            $identity = $this->redeemer->redeem($match[1], $origin, $path);
        } catch (WebSocketTicketUnavailableException $exception) {
            throw new AuthenticationUnavailableException('WebSocket authentication is unavailable.', previous: $exception);
        }

        return $identity === null ? null : [
            'user_id' => $identity->userId,
            'access_token_id' => $identity->accessTokenId,
        ];
    }
}
