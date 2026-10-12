<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel;

abstract class AbstractWebSocketController
{
    /**
     * @param string $userId the authenticated user
     * @param string $accessTokenId the access token the handshake presented; revoking it closes the connection
     */
    abstract public function onOpen(int $fd, string $userId, string $accessTokenId): void;

    abstract public function onMessage(int $fd, string $data): void;

    abstract public function onClose(int $fd): void;
}
