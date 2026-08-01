<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel;

abstract class AbstractWebSocketController
{
    abstract public function onOpen(int $fd, string $userId): void;

    abstract public function onMessage(int $fd, string $data): void;

    abstract public function onClose(int $fd): void;
}
