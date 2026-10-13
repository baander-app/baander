<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use Psr\Log\LoggerInterface;

/**
 * Closes the WebSocket connections of a revoked token chain, and never fails the revocation.
 *
 * The chain is revoked before this runs, so the outcome of the close changes nothing for the
 * caller. A connection this close could not end is closed by the socket reconciliation within
 * its next tick; the log names the chain and how many connections it concerned, never a token.
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
        try {
            $this->liveConnections->closeForAccessTokens($accessTokenIds);
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning(
                'No web server runs in this container, so the open WebSocket connections of token chain "{chain}" were not closed.',
                ['chain' => $chainId->toString()],
            );
        } catch (ServerControlException $exception) {
            $this->logger->error(
                'The web server did not close the WebSocket connections of the revoked token chain "{chain}".',
                ['chain' => $chainId->toString(), 'connections' => count($accessTokenIds), 'exception' => $exception],
            );
        }
    }
}
