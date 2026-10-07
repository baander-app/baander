<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Issues first-party token pairs after password or passkey login.
 *
 * The Symfony authenticators verify the user's credentials; this handler mints a
 * DPoP-bound access token and a refresh token that starts a new rotation chain.
 * The authorization code and device code grants issue through the same
 * TokenPairIssuer in their own handlers.
 */
final readonly class IssueTokenHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private UserRepositoryInterface $userRepository,
        private TokenPairIssuer $tokenPairIssuer,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(IssueTokenCommand $command): TokenResponseDTO
    {
        $client = $this->clientRepository->findClientByUuid($command->getClientId());
        if ($client === null) {
            throw new RuntimeException('Client not found.');
        }
        if ($client->isRevoked()) {
            throw new RuntimeException('Client has been revoked.');
        }

        $user = $this->userRepository->findByUuid($command->getUserId());
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        return $this->tokenPairIssuer->issue(
            client: $client,
            user: $user,
            requestedScopes: $command->getScopes(),
            dpopJkt: $command->getDpopJkt(),
            tokenName: $command->getTokenName(),
            clientFingerprint: $command->getClientFingerprint(),
            userAgent: $command->getUserAgent(),
            ipAddress: $command->getIpAddress(),
        );
    }
}
