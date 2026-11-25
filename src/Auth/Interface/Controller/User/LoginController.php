<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\User;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\VerifiedPasswordLogin;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\TotpStepAlreadySpentException;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Interface\Request\User\LoginRequest;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth', name: 'auth_login_')]
final class LoginController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly string $spaClientId,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/auth/login',
        summary: 'Authenticate with email and password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: LoginRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: TokenResource::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Invalid credentials',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The DPoP nonce store is unavailable, so the proof could not be checked, or issuing the session waited too long on a database lock; retry after the Retry-After delay. Carries no DPoP-Nonce header.',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        description: 'Seconds to wait before retrying',
                        schema: new OA\Schema(type: 'integer', example: 5),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Only the password authenticator sets this, after verifying the proof and the credentials.
        $login = $request->attributes->get(VerifiedPasswordLogin::class);
        if (!$login instanceof VerifiedPasswordLogin) {
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

        try {
            $envelope = $this->bus->dispatch(new IssueTokenCommand(
                clientId: $client->getId(),
                userId: $user->getId(),
                dpopJkt: $login->dpopJkt,
                credentialEpoch: $login->credentialEpoch,
                grant: $login->grant(),
                ipAddress: $request->getClientIp(),
                userAgent: $request->headers->get('User-Agent'),
                clientFingerprint: $request->headers->get(BaanderHeader::ClientFingerprint->value),
                totpStep: $login->totpStep,
                passwordHashUpgrade: $login->passwordHashUpgrade,
            ));
        } catch (HandlerFailedException $exception) {
            $cause = HandlerFailure::cause($exception);
            if ($cause instanceof TotpStepAlreadySpentException) {
                // Another request spent the code first; the tokens were rolled back with the step.
                return $this->invalidCredentials();
            }
            if ($cause instanceof CredentialsChangedException) {
                // The password changed after this login checked it; the password it used is gone.
                return $this->invalidCredentials();
            }
            throw $exception;
        }

        $tokenResponse = $envelope->last(HandledStamp::class)?->getResult();

        $response = $this->successResponse([
            ...TokenResource::from($tokenResponse),
            'user' => UserResource::from($user),
        ]);
        $response->headers->set('DPoP-Nonce', $login->nextNonce);

        return $response;
    }

    /** The answer a wrong password or code gets from the password authenticator. */
    private function invalidCredentials(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $this->trans('errors.invalid_credentials', domain: 'auth'),
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
