<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
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
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly string $spaClientId,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/login/passkey',
        summary: 'Authenticate with a WebAuthn passkey',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'userId', description: 'Optional user ID to get passkey options for', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(ref: new Model(type: TokenResource::class)),
            ),
            new OA\Response(response: '401', description: 'Invalid passkey or verification failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/passkey', name: 'passkey', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $securityUser = $this->security->getUser();
        if ($securityUser === null) {
            return $this->unauthorized();
        }

        $client = $this->clientRepository->findClientByPublicId(new PublicId($this->spaClientId));

        if ($client === null || $client->isRevoked()) {
            return $this->errorResponse('Invalid client configuration.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user = $this->userRepository->findByUuid(
            \App\Shared\Domain\Model\Uuid::fromString($securityUser->getId()),
        );

        if ($user === null) {
            return $this->unauthorized();
        }

        $envelope = $this->bus->dispatch(new IssueTokenCommand(
            grantType: 'direct_grant',
            clientId: $client->getId(),
            userId: $user->getId(),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            clientFingerprint: $request->headers->get('X-Client-Fingerprint'),
        ));

        $tokenResponse = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse([
            ...TokenResource::from($tokenResponse),
            'user' => UserResource::from($user),
        ]);
    }
}
