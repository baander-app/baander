<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use Swoole\Server;

/** The server's onPipeMessage callback: routes control requests and replies between HTTP workers. */
final readonly class ControlPipeMessageHandler
{
    public function __construct(private ServerControlCoordinator $coordinator)
    {
    }

    public function __invoke(Server $server, int $fromWorkerId, mixed $message): void
    {
        if (!is_array($message)) {
            return;
        }
        match ($message[ServerControlCoordinator::MESSAGE_MARKER] ?? null) {
            'request' => $this->coordinator->answer($message, $fromWorkerId),
            'reply' => $this->coordinator->deliverReply($message),
            default => null,
        };
    }
}
