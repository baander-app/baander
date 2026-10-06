<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\VerifiedPasskeyLogin;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth/login', name: 'auth_passkey_login_')]
final class PasskeyLoginController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly string $spaClientId,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/login/passkey',
        description: 'Requires a DPoP proof carrying a server-issued nonce, as password login does. The proof is checked before the assertion, so a nonce challenge leaves the assertion valid for a retry. The issued tokens are bound to the proof key.',
        summary: 'Authenticate with a WebAuthn passkey',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['challengeKey', 'response'], properties: [
                    new OA\Property(property: 'challengeKey', description: 'Key returned by /api/auth/passkey/authenticate/options', type: 'string'),
                    new OA\Property(property: 'response', description: 'WebAuthn assertion from navigator.credentials.get()', type: 'object'),
                    new OA\Property(property: 'userId', description: 'Optional user ID the assertion must belong to', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(ref: new Model(type: TokenResource::class)),
            ),
            new OA\Response(response: '400', description: 'DPoP nonce challenge (use_dpop_nonce) with a DPoP-Nonce header, or an invalid proof. A missing proof returns an ApiError.', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Invalid passkey or verification failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/passkey', name: 'passkey', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Only the passkey authenticator sets this, after verifying the proof and the assertion.
        $login = $request->attributes->get(VerifiedPasskeyLogin::class);
        if (!$login instanceof VerifiedPasskeyLogin) {
            return $this->unauthorized();
        }

        $client = $this->clientRepository->findClientByPublicId(new PublicId($this->spaClientId));

        if ($client === null || $client->isRevoked()) {
            return $this->errorResponse('Invalid client configuration.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user = $this->userRepository->findByUuid($login->userId);

        if ($user === null) {
            return $this->unauthorized();
        }

        $envelope = $this->bus->dispatch(new IssueTokenCommand(
            clientId: $client->getId(),
            userId: $user->getId(),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            clientFingerprint: $request->headers->get(BaanderHeader::ClientFingerprint->value),
            dpopJkt: $login->dpopJkt,
        ));

        $tokenResponse = $envelope->last(HandledStamp::class)?->getResult();

        $response = $this->successResponse([
            ...TokenResource::from($tokenResponse),
            'user' => UserResource::from($user),
        ]);
        $response->headers->set('DPoP-Nonce', $login->nextNonce);

        return $response;
    }
}
