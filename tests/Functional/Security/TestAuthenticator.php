<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Test authenticator that accepts X-Test-User-Id header.
 *
 * Only active in test environment via security_test.yaml override.
 */
final class TestAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-Test-User-Id');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $userId = $request->headers->get('X-Test-User-Id');

        if ($userId === null || $userId === '') {
            throw new UserNotFoundException('X-Test-User-Id header is empty.');
        }

        $badge = new UserBadge(
            $userId,
            function (string $uuid): SecurityUser {
                $user = $this->userRepository->findByUuid(Uuid::fromString($uuid));

                if ($user === null) {
                    throw new UserNotFoundException(sprintf('User with UUID "%s" not found.', $uuid));
                }

                return new SecurityUser(
                    $user->getId()->toString(),
                    $user->getEmail(),
                    $user->getPassword(),
                    $user->getRoles(),
                );
            },
        );

        return new SelfValidatingPassport($badge);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => [
                'message' => $exception->getMessage(),
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
