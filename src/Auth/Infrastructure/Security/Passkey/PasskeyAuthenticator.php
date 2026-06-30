<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Domain\Repository\UserRepositoryInterface;
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
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasskeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly UserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/auth/login/passkey'
            && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');

        $challengeKey = $data['challengeKey'] ?? '';
        $response = $data['response'] ?? null;

        if ($challengeKey === '' || !is_array($response)) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $command = new AuthenticatePasskeyCommand(
            userId: $data['userId'] ?? null,
            challengeKey: $challengeKey,
            response: $response,
        );

        try {
            $userId = $this->commandBus->dispatch($command)->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $e) {
            $this->logger->debug('Passkey authentication failed.', ['exception' => $e]);
            throw new BadCredentialsException('Invalid credentials.', 0, $e);
        }

        // The handler returns a user ID UUID string; use a custom loader that resolves
        // via UUID instead of going through UserProvider (which expects an email).
        $uuid = Uuid::fromString($userId);
        return new SelfValidatingPassport(
            new UserBadge((string) $userId, function () use ($uuid): SecurityUser {
                $user = $this->userRepository->findByUuid($uuid);
                if ($user === null) {
                    throw new BadCredentialsException('Invalid credentials.');
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

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => [
                'message' => $exception->getMessageKey(),
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
