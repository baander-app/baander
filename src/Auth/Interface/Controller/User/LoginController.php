<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\User;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\OAuth\ValueObject\DpopValidationResult;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
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
#[Route('/api/auth', name: 'auth_login_')]
final class LoginController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly DpopProofValidator $dpopProofValidator,
        private readonly DpopNonceManager $dpopNonceManager,
        private readonly string $spaClientId,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Authenticate with email and password',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: \App\Auth\Interface\Request\User\LoginRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(ref: new Model(type: TokenResource::class)),
            ),
            new OA\Response(response: '401', description: 'Invalid credentials', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
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

        // Validate DPoP proof
        $dpopHeader = $request->headers->get('DPoP');
        if ($dpopHeader === null || $dpopHeader === '') {
            return $this->errorResponse('DPoP proof header is required.', Response::HTTP_BAD_REQUEST);
        }

        // Nonce challenge-response (Auth0-style): require nonce in proof
        $proofNonce = $this->dpopProofValidator->extractNonce($dpopHeader);
        if ($proofNonce === null || $proofNonce === '') {
            return $this->dpopNonceManager->createChallengeResponse();
        }

        // Validate the nonce
        if (!$this->dpopNonceManager->isValid($proofNonce)) {
            return $this->dpopNonceManager->createChallengeResponse(
                'Authorization server requires a fresh nonce in DPoP proof.',
            );
        }

        $result = $this->dpopProofValidator->validate($dpopHeader, $request);
        if (!$result->isValid()) {
            return $this->dpopNonceManager->createChallengeResponse(
                $result->getErrorDescription() ?? $result->getError() ?? 'DPoP proof validation failed.',
            );
        }

        $envelope = $this->bus->dispatch(new IssueTokenCommand(
            grantType: 'direct_grant',
            clientId: $client->getId(),
            userId: $user->getId(),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            dpopJkt: $result->getJkt(),
        ));

        $tokenResponse = $envelope->last(HandledStamp::class)?->getResult();

        $nonce = $this->dpopNonceManager->generateNonce();
        $this->dpopNonceManager->storeNonce($nonce);

        $response = $this->successResponse([
            ...TokenResource::from($tokenResponse),
            'user' => UserResource::from($user),
        ]);
        $response->headers->set('DPoP-Nonce', $nonce);

        return $response;
    }
}
