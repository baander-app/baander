<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Application\Http\BaanderHeader;
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
 * Test authenticator that accepts X-Baander-Test-User-Id header.
 *
 * The request counts as a first-party token by default. X-Baander-Test-Client-Scopes
 * models a delegated token instead: its space-separated value is the token's scopes,
 * and the user authenticates with ROLE_USER alone, as the API authenticator does.
 *
 * Only active in test environment via security_test.yaml override.
 */
final class TestAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has(BaanderHeader::TestUserId->value);
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $userId = $request->headers->get(BaanderHeader::TestUserId->value);

        if ($userId === null || $userId === '') {
            throw new UserNotFoundException(BaanderHeader::TestUserId->value . ' header is empty.');
        }

        $firstParty = !$request->headers->has(BaanderHeader::TestClientScopes->value);
        $request->attributes->set(AccessTokenAttributes::FIRST_PARTY, $firstParty);
        $request->attributes->set(AccessTokenAttributes::SCOPES, $firstParty ? [] : self::delegatedScopes($request));

        $badge = new UserBadge(
            $userId,
            function (string $uuid) use ($firstParty, $request): SecurityUser {
                $user = $this->userRepository->findByUuid(Uuid::fromString($uuid));

                if ($user === null) {
                    throw new UserNotFoundException(sprintf('User with UUID "%s" not found.', $uuid));
                }
                // A test request stands for a token issued under the user's current credentials.
                $request->attributes->set(AccessTokenAttributes::CREDENTIAL_EPOCH, $user->getCredentialEpoch());

                return new SecurityUser(
                    $user->getId()->toString(),
                    $user->getEmail(),
                    $user->getPassword(),
                    $firstParty ? $user->getRoles() : ['ROLE_USER'],
                );
            },
        );

        return new SelfValidatingPassport($badge);
    }

    /** @return list<string> */
    private static function delegatedScopes(Request $request): array
    {
        $value = (string) $request->headers->get(BaanderHeader::TestClientScopes->value, '');

        return array_values(array_filter(explode(' ', $value), static fn (string $scope): bool => $scope !== ''));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'error' => [
                'message' => $exception->getMessage(),
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
