<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\DTO\VerifiedPasskeyLogin;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\AuthenticationFailureMessage;
use App\Auth\Infrastructure\Security\SecurityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Authenticates passkey login (WebAuthn assertion) on the API firewall.
 *
 * The DPoP proof is checked before the ceremony, so a nonce challenge does not
 * consume the WebAuthn challenge or the authenticator's signature counter.
 * On success it stores a VerifiedPasskeyLogin on the request; the login
 * controller issues tokens only from it.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly UserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly DpopTokenRequestVerifier $dpopVerifier,
        private readonly AuthenticationFailureMessage $failureMessage,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->getPathInfo() === '/api/auth/login/passkey'
            && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $proofKey = $this->dpopVerifier->verify($request);
        if ($proofKey instanceof JsonResponse) {
            throw new DpopProofRejectedException($proofKey);
        }

        try {
            $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        } catch (\Throwable) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }
        if (!is_array($data)) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        $challengeKey = $data['challengeKey'] ?? '';
        $response = $data['response'] ?? null;
        $claimedUserId = $data['userId'] ?? null;

        if (!is_string($challengeKey) || $challengeKey === '' || !is_array($response)
            || ($claimedUserId !== null && !is_string($claimedUserId))) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        $command = new AuthenticatePasskeyCommand(
            userId: $claimedUserId,
            challengeKey: $challengeKey,
            response: $response,
        );

        try {
            $userId = $this->commandBus->dispatch($command)->last(HandledStamp::class)?->getResult();
            if (!is_string($userId)) {
                throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
            }
            $uuid = Uuid::fromString($userId);
        } catch (\Throwable $e) {
            $this->logger->debug('Passkey authentication failed.', ['exception' => $e]);
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS, 0, $e);
        }

        $request->attributes->set(VerifiedPasskeyLogin::class, new VerifiedPasskeyLogin(
            $uuid,
            $proofKey,
            $this->dpopVerifier->issueNonce(),
        ));

        // The handler returns a user ID UUID string; use a custom loader that resolves
        // via UUID instead of going through UserProvider (which expects an email).
        return new SelfValidatingPassport(
            new UserBadge((string) $userId, function () use ($uuid): SecurityUser {
                $user = $this->userRepository->findByUuid($uuid);
                if ($user === null) {
                    throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
                }
                // Same rule as password login: a disabled account cannot sign in.
                if ($user->isDisabled()) {
                    throw new CustomUserMessageAuthenticationException(AuthenticationFailureMessage::ACCOUNT_DISABLED);
                }
                return new SecurityUser(
                    $user->getId()->toString(),
                    $user->getEmail(),
                    $user->getPassword(),
                    $user->getRoles(),
                );
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof DpopProofRejectedException) {
            return $exception->response;
        }

        return new JsonResponse([
            'error' => [
                'message' => $this->failureMessage->of($exception, $request),
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
