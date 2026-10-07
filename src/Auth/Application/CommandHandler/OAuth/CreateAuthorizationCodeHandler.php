<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use DateInterval;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records the signed-in user's decision on an authorization request (RFC 6749 section 4.1.2).
 *
 * Approval issues a single-use, PKCE-bound authorization code for the
 * validated redirect URI. Denial answers access_denied at the same redirect
 * URI once the client and redirect URI are valid.
 */
final readonly class CreateAuthorizationCodeHandler
{
    private DateInterval $authCodeTtl;

    public function __construct(
        private AuthorizationRequestValidator $validator,
        private AuthCodeRepositoryInterface $authCodeRepository,
        int $authCodeTtl,
    ) {
        $this->authCodeTtl = new DateInterval(sprintf('PT%dS', $authCodeTtl));
    }

    /** @throws OAuthProtocolException */
    #[AsMessageHandler]
    public function __invoke(CreateAuthorizationCodeCommand $command): AuthorizationResponseDTO
    {
        if (!$command->approved) {
            [, $redirectUri] = $this->validator->validateClient($command->clientId, $command->redirectUri);

            return AuthorizationResponseDTO::denied($redirectUri);
        }

        $request = $this->validator->validate(
            $command->userId,
            $command->responseType,
            $command->clientId,
            $command->redirectUri,
            $command->codeChallenge,
            $command->codeChallengeMethod,
            $command->scopes,
        );

        $code = AuthCode::create(
            $request->user,
            $request->client,
            $request->redirectUri,
            $request->codeChallenge,
            $request->codeChallengeMethod,
            $request->scopes,
            $this->authCodeTtl,
        );
        $this->authCodeRepository->save($code);

        return AuthorizationResponseDTO::code($request->redirectUri, $code->getCodeId()->toString());
    }
}
