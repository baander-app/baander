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

final class WithWebSocketHandler implements Configurator
{

    /**
     * @param (callable(SwooleRequest): ?string)|null $authenticator Authenticates the handshake request.
     *   Returns the authenticated user ID string on success, or null to reject.
     *   When null, authentication is skipped (e.g., no WS controller configured).
     * @param list<string>|null $allowedOrigins Origin header allowlist. Null means allow all (dev mode).
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
                if (!$this->validateOrigin($request)) {
                    $response->status(403);
                    $response->end('Forbidden: invalid origin');

                    return;
                }

                $userId = $this->authenticate($request);
                if ($userId === null) {
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
                $this->controller->onOpen($fd, $userId);
            } catch (\Throwable $exception) {
                error_log(sprintf('[WS Handshake] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
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
     * When allowedOrigins is null (dev mode), all origins are accepted.
     */
    private function validateOrigin(SwooleRequest $request): bool
    {
        if ($this->allowedOrigins === null || $this->allowedOrigins === []) {
            return true;
        }

        $origin = $request->header['origin'] ?? '';

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Calls the authenticator closure to verify the request and extract the user ID.
     * Returns null if authentication fails or no authenticator is configured.
     */
    private function authenticate(SwooleRequest $request): ?string
    {
        if ($this->authenticator === null) {
            return null;
        }

        if ($this->authenticator instanceof \Closure) {
            return ($this->authenticator)($request);
        }

        if (is_object($this->authenticator) && method_exists($this->authenticator, 'authenticate')) {
            return $this->authenticator->authenticate($request);
        }

        if (is_callable($this->authenticator)) {
            return ($this->authenticator)($request);
        }

        return null;
    }
}
