<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\User;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Interface\Request\OAuth\RefreshTokenRequest;
use App\Auth\Interface\Request\User\RegisterRequest;
use App\Auth\Interface\Request\User\RequestPasswordResetRequest;
use App\Auth\Interface\Request\User\ChangeEmailRequest;
use App\Auth\Interface\Request\User\ChangePasswordRequest;
use App\Auth\Interface\Request\User\UpdateProfileRequest;
use App\Auth\Interface\Request\User\VerifyEmailRequest;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth', name: 'auth_')]
final class AuthController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly UserPortInterface $userService,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly PasskeyService $passkeyService,
        private readonly DpopProofValidator $dpopProofValidator,
        private readonly DpopNonceManager $dpopNonceManager,
        private readonly JsonEncoder $jsonEncoder,
    )
    {
    }

    #[OA\Post(
        path: '/api/auth/register',
        summary: 'Register a new user',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['name', 'email', 'password'], properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Alice'),
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                        new OA\Property(property: 'password', type: 'string', example: '********'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'User created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: new Model(type: UserResource::class)),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '400', description: 'Bad request', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $registerRequest): JsonResponse
    {
        $command = new RegisterUserCommand(
            email: new Email($registerRequest->email),
            name: $registerRequest->name,
            plainPassword: $registerRequest->password,
        );

        try {
            $envelope = $this->commandBus->dispatch($command);
            $stamp = $envelope->last(HandledStamp::class);
            $user = $stamp?->getResult();
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->successResponse(UserResource::from($user), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Revoke the current access token',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Token revoked',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Token revoked.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization', '');
        $tokenId = null;

        if (str_starts_with($authHeader, 'DPoP ')) {
            $tokenId = substr($authHeader, 5);
        } elseif (str_starts_with($authHeader, 'Bearer ')) {
            $tokenId = substr($authHeader, 7);
        }

        if ($tokenId === null || $tokenId === '') {
            return $this->unauthorized();
        }

        $command = new RevokeTokenCommand(
            tokenId: $tokenId,
            revokeChain: false,
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // RFC 7009: Always return 200 OK, even if token was not found
        return $this->successResponse([
            'message' => $this->trans('success.token_revoked', domain: 'auth'),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/refresh',
        summary: 'Refresh an access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['refreshToken'], properties: [
                        new OA\Property(property: 'refreshToken', type: 'string', example: 'your-refresh-token'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Token refreshed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'tokenType', type: 'string', example: 'Bearer'),
                            new OA\Property(property: 'expiresIn', type: 'integer', example: 3600),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Invalid refresh token', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(#[MapRequestPayload] RefreshTokenRequest $payload, Request $request): JsonResponse
    {
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

        $command = new RefreshTokenCommand(
            refreshTokenId: $payload->refreshToken,
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            dpopJkt: $result->getJkt(),
        );

        try {
            $envelope = $this->commandBus->dispatch($command);
            $stamp = $envelope->last(HandledStamp::class);
            $tokenResponse = $stamp?->getResult();
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        $nonce = $this->dpopNonceManager->generateNonce();
        $this->dpopNonceManager->storeNonce($nonce);

        $response = $this->successResponse(TokenResource::from($tokenResponse));
        $response->headers->set('DPoP-Nonce', $nonce);

        return $response;
    }

    #[OA\Post(
        path: '/api/auth/password/reset-request',
        summary: 'Request a password reset email',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Reset email sent (always returns 200)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Password reset email sent.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/password/reset-request', name: 'password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(#[MapRequestPayload] RequestPasswordResetRequest $payload): JsonResponse
    {
        try {
            $emailValue = new Email($payload->email);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse($this->trans('errors.invalid_email', domain: 'auth'), Response::HTTP_BAD_REQUEST);
        }

        $command = new RequestPasswordResetCommand(email: $emailValue);

        try {
            $this->commandBus->dispatch($command);
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Always return 200 to avoid revealing whether the email exists
        return $this->successResponse([
            'message' => $this->trans('success.password_reset_sent', domain: 'auth'),
        ]);
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Get the current authenticated user profile',
        responses: [
            new OA\Response(
                response: '200',
                description: 'User profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: new Model(type: UserResource::class)),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        return $this->successResponse(UserResource::from($user));
    }

    #[OA\Put(
        path: '/api/auth/me',
        summary: 'Update the current user profile',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['name'], properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Alice Johnson'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'publicId', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'email', type: 'string', format: 'email'),
                            new OA\Property(property: 'emailVerifiedAt', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'signal', description: 'WebAuthn CurrentUserDetails signal for updating the passkey picker', type: 'object', nullable: true),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/me', name: 'me_update', methods: ['PUT'])]
    public function updateMe(#[MapRequestPayload] UpdateProfileRequest $payload): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $user->updateName($payload->name);

        try {
            $this->userService->save($user);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Send CurrentUserDetails signal so the client updates the passkey picker
        try {
            $signal = $this->passkeyService->createCurrentUserDetailsSignal(
                $user->getId()->toString(),
                $user->getEmail(),
                $user->getName(),
            );
            $signalData = $this->jsonEncoder->decode($signal, 'json');
        } catch (\UnexpectedValueException) {
            $signalData = null;
        }

        return $this->successResponse([
            ...UserResource::from($user),
            'signal' => $signalData,
        ]);
    }

    #[OA\Post(
        path: '/api/auth/email/verify',
        summary: 'Verify an email address with a token',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['token'], properties: [
                        new OA\Property(property: 'token', type: 'string', example: 'verification-token-abc123'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Verification status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Email verification not yet implemented.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/email/verify', name: 'email_verify', methods: ['POST'])]
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $payload): JsonResponse
    {
        // TODO: Implement email verification with token lookup.
        // This requires a VerifyEmailCommand and handler that:
        // 1. Looks up the token in the database
        // 2. Validates it hasn't expired
        // 3. Marks the user's email as verified
        // For now, return a placeholder response.

        return $this->successResponse([
            'message' => $this->trans('success.email_not_implemented', domain: 'auth'),
        ]);
    }

    #[OA\Put(
        path: '/api/auth/me/email',
        summary: 'Change the current user email',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Email changed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: new Model(type: UserResource::class)),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Email already taken', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/me/email', name: 'me_email', methods: ['PUT'])]
    public function changeEmail(#[MapRequestPayload] ChangeEmailRequest $payload): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $newEmail = new Email($payload->email);

        if ($this->userService->existsWithEmail($newEmail) && $newEmail->toString() !== $user->getEmail()) {
            return $this->errorResponse('This email address is already in use.', Response::HTTP_CONFLICT);
        }

        $user->changeEmail($newEmail->toString());

        try {
            $this->userService->save($user);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse(UserResource::from($user));
    }

    #[OA\Put(
        path: '/api/auth/me/password',
        summary: 'Change the current user password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['currentPassword', 'newPassword'], properties: [
                        new OA\Property(property: 'currentPassword', type: 'string', example: '********'),
                        new OA\Property(property: 'newPassword', type: 'string', example: '********', minLength: 8),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Password changed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Password changed.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error or wrong current password', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/me/password', name: 'me_password', methods: ['PUT'])]
    public function changePassword(
        #[MapRequestPayload] ChangePasswordRequest $payload,
        \App\Auth\Application\Port\PasswordHasherInterface $passwordHasher,
    ): JsonResponse {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        if (!$passwordHasher->verify($payload->currentPassword, $user->getPassword())) {
            return $this->errorResponse('Current password is incorrect.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->changePassword($passwordHasher->hash($payload->newPassword));

        try {
            $this->userService->save($user);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse([
            'message' => $this->trans('success.password_changed', domain: 'auth'),
        ]);
    }

    private function getCurrentUser(): ?User
    {
        $securityUser = $this->security->getUser();
        if ($securityUser === null) {
            return null;
        }

        return $this->userService->findByUuid(
            Uuid::fromString($securityUser->getId()),
        );
    }
}
