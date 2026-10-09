<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketUserDisconnectOperation;

/**
 * Closes a user's live connections through the server control channel: in-process
 * inside an HTTP worker, through the control socket from a console command in the
 * web container. The channel is local to the web container, so no other container
 * gains a way to act on the web server.
 */
final readonly class SwooleLiveConnections implements LiveConnectionsPortInterface
{
    public function __construct(
        private ServerControlPortInterface $serverControl,
    ) {
    }

    public function closeForUser(Uuid $userId): int
    {
        $result = $this->serverControl->execute(
            WebSocketUserDisconnectOperation::NAME,
            ['user_id' => $userId->toString()],
        );
        if (!$result->isComplete()) {
            throw new ServerControlException(implode('; ', $result->errors) ?: 'a worker did not answer');
        }

        $answer = array_values($result->results)[0] ?? null;
        if (!is_array($answer) || !is_int($answer['closed'] ?? null)) {
            throw new ServerControlException('the web server answered without a count of closed connections');
        }

        return $answer['closed'];
    }
}
