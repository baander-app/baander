<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\Configurator;

use Swoole\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\AbstractWebSocketController;
use SwooleBundle\SwooleBundle\Server\Exception\AuthenticationUnavailableException;

final class WithWebSocketHandler implements Configurator
{

    /**
     * @param (callable(SwooleRequest): ?array{user_id: string, access_token_id: string})|object|null $authenticator
     *   Authenticates the handshake request: a callable, or an object with an `authenticate(SwooleRequest)`
     *   method. Returns the authenticated user ID and the ID of the access token the request presented,
     *   both as strings, on success, or null to reject. A missing authenticator rejects every handshake.
     * @param list<string>|null $allowedOrigins Exact serialized Origin allowlist. Null and an empty list deny all origins.
     */
    public function __construct(
        private readonly AbstractWebSocketController $controller,
        private readonly CoWrapper $coWrapper,
        private readonly mixed $authenticator = null,
        private readonly ?array $allowedOrigins = null,
    ) {}

    public function configure(Server $server): void
    {
        $server->on('Handshake', function (SwooleRequest $request, SwooleResponse $response): void {
            $this->handleHandshake($request, $response);
        });
        $server->on('Message', $this->handleMessage(...));
        $server->on('Close', $this->handleClose(...));
    }

    /**
     * Handles the WebSocket handshake. Validates Origin header, authenticates
     * the request, and upgrades the connection on success.
     */
    private function handleHandshake(SwooleRequest $request, SwooleResponse $response): void
    {
        $this->coWrapper->go(function () use ($request, $response): void {
            try {
                if (!$this->validateHandshake($request)) {
                    $response->status(400);
                    $response->end('Invalid WebSocket handshake');

                    return;
                }

                if (!$this->validateOrigin($request)) {
                    $response->status(403);
                    $response->end('Forbidden: invalid origin');

                    return;
                }

                $identity = $this->authenticate($request);
                if ($identity === null) {
                    $response->status(401);
                    $response->end('Unauthorized');

                    return;
                }

                // Complete the WebSocket handshake manually.
                // Swoole\WebSocket\Server with coroutines does not support $response->upgrade(),
                // so we send the 101 response with the required Sec-WebSocket-Accept header.
                $secWebSocketKey = $request->header['sec-websocket-key'] ?? '';
                $secWebSocketAccept = base64_encode(sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

                $response->header('Upgrade', 'websocket');
                $response->header('Connection', 'Upgrade');
                $response->header('Sec-WebSocket-Accept', $secWebSocketAccept);
                $response->status(101);
                $response->end();

                // When onHandshake is registered, Swoole does NOT call onOpen.
                // Invoke the controller's onOpen directly after successful handshake.
                $fd = (int) $request->fd;
                $this->controller->onOpen($fd, $identity['user_id'], $identity['access_token_id']);
            } catch (AuthenticationUnavailableException) {
                $response->header('Cache-Control', 'no-store');
                $response->header('Retry-After', '1');
                $response->status(503);
                $response->end('Authentication unavailable');
            } catch (\Throwable) {
                error_log('[WS Handshake] Authentication or connection registration failed.');
                $response->status(500);
                $response->end('Internal server error');
            }
        });
    }

    private function handleMessage(Server $server, Frame $frame): void
    {
        $this->coWrapper->go(function () use ($frame): void {
            $this->controller->onMessage((int) $frame->fd, $frame->data);
        });
    }

    private function handleClose(Server $server, int $fd): void
    {
        $this->coWrapper->go(function () use ($fd): void {
            $this->controller->onClose($fd);
        });
    }

    /**
     * Validates the Origin header against the configured allowlist.
     * Missing, malformed or unconfigured origins fail closed.
     */
    private function validateOrigin(SwooleRequest $request): bool
    {
        if ($this->allowedOrigins === null || $this->allowedOrigins === []) {
            return false;
        }

        $origin = $request->header['origin'] ?? '';

        if (!is_string($origin) || $origin === 'null' || preg_match('/[\x00-\x20\x7f]/', $origin) === 1) {
            return false;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []) {
            return false;
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $serialized = $parts['scheme'] . '://' . strtolower($parts['host']);
        if ($port !== ($parts['scheme'] === 'https' ? 443 : 80)) {
            $serialized .= ':' . $port;
        }

        return $origin === $serialized && in_array($origin, $this->allowedOrigins, true);
    }

    private function validateHandshake(SwooleRequest $request): bool
    {
        if (($request->server['request_method'] ?? null) !== 'GET'
            || ($request->server['request_uri'] ?? null) !== '/api/ws') {
            return false;
        }
        $query = $request->server['query_string'] ?? null;
        $key = $request->header['sec-websocket-key'] ?? null;
        $version = $request->header['sec-websocket-version'] ?? null;
        $upgrade = $request->header['upgrade'] ?? null;
        $connection = $request->header['connection'] ?? null;
        if (!is_string($query) || preg_match('/\Aticket=[a-f0-9]{64}\z/D', $query) !== 1
            || !is_string($key) || !is_string($upgrade) || !is_string($connection)
            || $version !== '13' || strcasecmp($upgrade, 'websocket') !== 0
            || preg_match('/[\x00-\x1f\x7f]/', $connection) === 1) {
            return false;
        }
        $decodedKey = base64_decode($key, true);
        if ($decodedKey === false || strlen($decodedKey) !== 16 || base64_encode($decodedKey) !== $key) {
            return false;
        }

        $tokens = array_map(static fn (string $token): string => strtolower(trim($token)), explode(',', $connection));
        foreach ($tokens as $token) {
            if (preg_match("/\A[!#$%&'*+.^_`|~0-9a-z-]+\z/D", $token) !== 1) {
                return false;
            }
        }

        return in_array('upgrade', $tokens, true);
    }

    /**
     * Calls the authenticator to verify the request and extract the user ID and the access token ID.
     * Returns null if authentication fails, the answer lacks either ID, or no authenticator is configured.
     *
     * @return array{user_id: string, access_token_id: string}|null
     */
    private function authenticate(SwooleRequest $request): ?array
    {
        if ($this->authenticator === null) {
            return null;
        }

        if (is_object($this->authenticator) && method_exists($this->authenticator, 'authenticate')) {
            $identity = $this->authenticator->authenticate($request);
        } elseif (is_callable($this->authenticator)) {
            $identity = ($this->authenticator)($request);
        } else {
            return null;
        }

        if (!is_array($identity)) {
            return null;
        }
        $userId = $identity['user_id'] ?? null;
        $accessTokenId = $identity['access_token_id'] ?? null;
        if (!is_string($userId) || $userId === '' || !is_string($accessTokenId) || $accessTokenId === '') {
            return null;
        }

        return ['user_id' => $userId, 'access_token_id' => $accessTokenId];
    }
}
