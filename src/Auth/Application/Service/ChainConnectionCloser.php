<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Closes the WebSocket connections of revoked token chains, and never fails the revocation.
 *
 * The chains are revoked before this runs, so the outcome of the close changes nothing for the
 * caller. A connection this close could not end is closed by the socket reconciliation within
 * its next tick; the log names the chain, or the user whose sessions a credential change
 * ended, and how many connections it concerned, never a token.
 */
final readonly class ChainConnectionCloser
{
    public function __construct(
        private LiveConnectionsPortInterface $liveConnections,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $accessTokenIds */
    public function close(array $accessTokenIds, ChainId $chainId): void
    {
        $this->closeOrLog(
            $accessTokenIds,
            'No web server runs in this container, so the open WebSocket connections of token chain "{chain}" were not closed.',
            'The web server did not close the WebSocket connections of the revoked token chain "{chain}".',
            ['chain' => $chainId->toString()],
        );
    }

    /**
     * The connections of every session a credential change of the user ended.
     *
     * @param list<string> $accessTokenIds
     */
    public function closeForUser(array $accessTokenIds, Uuid $userId): void
    {
        $this->closeOrLog(
            $accessTokenIds,
            'No web server runs in this container, so the open WebSocket connections of the sessions a credential change of user "{user}" ended were not closed.',
            'The web server did not close the WebSocket connections of the sessions a credential change of user "{user}" ended.',
            ['user' => $userId->toString()],
        );
    }

    /**
     * @param list<string>          $accessTokenIds
     * @param array<string, string> $subject
     */
    private function closeOrLog(array $accessTokenIds, string $notRunning, string $notClosed, array $subject): void
    {
        try {
            $this->liveConnections->closeForAccessTokens($accessTokenIds);
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning($notRunning, $subject);
        } catch (ServerControlException $exception) {
            $this->logger->error(
                $notClosed,
                [...$subject, 'connections' => count($accessTokenIds), 'exception' => $exception],
            );
        }
    }
}
