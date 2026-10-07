<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeDeviceCodeCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The device code grant (RFC 8628 section 3.4 and 3.5).
 *
 * While the user has not decided, a poll answers authorization_pending, or
 * slow_down when it came sooner than the current interval, which then grows by
 * 5 seconds. A denied request answers access_denied and an expired one
 * expired_token. An approved code is redeemed once, in the transaction that
 * stores the DPoP-bound token pair.
 */
final readonly class ExchangeDeviceCodeHandler
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
        private TokenPairIssuer $tokenPairIssuer,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ExchangeDeviceCodeCommand $command): TokenResponseDTO
    {
        $client = $this->clientAuthenticator->authenticate($command->clientId, $command->clientSecret);
        if (!$client->isDeviceClient()) {
            throw OAuthProtocolException::unauthorizedClient('The client is not registered for the device authorization grant.');
        }

        if ($command->deviceCode === null || $command->deviceCode === '') {
            throw OAuthProtocolException::invalidRequest('The device_code parameter is required.');
        }

        $deviceCode = $this->find($command->deviceCode);
        if ($deviceCode === null || !$deviceCode->getClient()->getId()->equals($client->getId())) {
            throw OAuthProtocolException::invalidGrant('The device code is invalid.');
        }
        if ($deviceCode->isConsumed()) {
            throw OAuthProtocolException::invalidGrant('The device code was already used.');
        }
        if ($deviceCode->isExpired()) {
            throw OAuthProtocolException::expiredToken();
        }
        if ($deviceCode->isDenied()) {
            throw OAuthProtocolException::accessDenied('The user denied the device authorization request.');
        }

        if ($deviceCode->isPending()) {
            $tooSoon = $deviceCode->recordPoll();
            $this->deviceCodeRepository->save($deviceCode);

            throw $tooSoon
                ? OAuthProtocolException::slowDown($deviceCode->getInterval())
                : OAuthProtocolException::authorizationPending();
        }

        $user = $deviceCode->getUser();
        if ($user === null || $user->isDisabled()) {
            throw OAuthProtocolException::invalidGrant('The approving user account is unavailable.');
        }

        return $this->tokenPairIssuer->issue(
            client: $client,
            user: $user,
            requestedScopes: $deviceCode->getScopeIdentifiers(),
            dpopJkt: $command->dpopJkt,
            clientFingerprint: $command->clientFingerprint,
            userAgent: $command->userAgent,
            ipAddress: $command->ipAddress,
            persistWithTokens: function () use ($deviceCode): void {
                if (!$this->deviceCodeRepository->redeem($deviceCode)) {
                    throw OAuthProtocolException::invalidGrant('The device code was already used or has expired.');
                }
            },
        );
    }

    private function find(string $deviceCode): ?DeviceCode
    {
        try {
            return $this->deviceCodeRepository->findByDeviceCode(TokenId::fromString($deviceCode));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
