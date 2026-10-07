<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RequestDeviceAuthorizationCommand;
use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use DateInterval;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the device authorization grant for a registered device client (RFC 8628 section 3.1).
 */
final readonly class RequestDeviceAuthorizationHandler
{
    private const int USER_CODE_ATTEMPTS = 5;

    /**
     * @param string $verificationUri Absolute URI of the page where the user enters the code
     */
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
        private ScopeAllowlist $scopeAllowlist,
        private string $verificationUri,
        private int $deviceCodeTtl,
        private int $pollingInterval,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RequestDeviceAuthorizationCommand $command): DeviceAuthorizationDTO
    {
        $client = $this->clientAuthenticator->identify($command->clientId);
        if (!$client->isDeviceClient()) {
            throw OAuthProtocolException::unauthorizedClient('The client is not registered for the device authorization grant.');
        }

        $scopes = array_map(
            static fn (string $scope): Scope => new Scope($scope),
            array_values(array_unique($this->scopeAllowlist->filter($command->scopes))),
        );

        $userCode = $this->unusedUserCode();
        $deviceCode = DeviceCode::create(
            $client,
            $userCode,
            $this->verificationUri,
            $this->verificationUri . '?user_code=' . rawurlencode($userCode),
            $scopes,
            new DateInterval(sprintf('PT%dS', $this->deviceCodeTtl)),
            $this->pollingInterval,
        );
        $this->deviceCodeRepository->save($deviceCode);

        return new DeviceAuthorizationDTO(
            deviceCode: $deviceCode->getDeviceCode()->toString(),
            userCode: $deviceCode->getUserCode(),
            verificationUri: $deviceCode->getVerificationUri(),
            verificationUriComplete: (string) $deviceCode->getVerificationUriComplete(),
            expiresIn: $this->deviceCodeTtl,
            interval: $deviceCode->getInterval(),
        );
    }

    private function unusedUserCode(): string
    {
        for ($attempt = 0; $attempt < self::USER_CODE_ATTEMPTS; ++$attempt) {
            $userCode = DeviceCode::generateUserCode();
            if ($this->deviceCodeRepository->findByUserCode($userCode) === null) {
                return $userCode;
            }
        }

        throw new RuntimeException('Could not generate an unused device user code.');
    }
}
