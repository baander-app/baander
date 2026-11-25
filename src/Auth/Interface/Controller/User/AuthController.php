<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\User;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\Command\User\ChangeOwnEmailCommand;
use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Command\User\ResendEmailVerificationCommand;
use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Exception\RegistrationClosedException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\VerificationLinkOtherAccountException;
use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Port\SelfRegistrationInterface;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Interface\Request\OAuth\RefreshTokenRequest;
use App\Auth\Interface\Request\User\ChangeEmailRequest;
use App\Auth\Interface\Request\User\ChangePasswordRequest;
use App\Auth\Interface\Request\User\RegisterRequest;
use App\Auth\Interface\Request\User\RequestPasswordResetRequest;
use App\Auth\Interface\Request\User\ResetPasswordRequest;
use App\Auth\Interface\Request\User\UpdateProfileRequest;
use App\Auth\Interface\Request\User\VerifyEmailRequest;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use OpenApi\Spec as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth', name: 'auth_')]
final class AuthController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly UserPortInterface $userService,
        private readonly PasskeyService $passkeyService,
        private readonly DpopProofValidator $dpopProofValidator,
        private readonly DpopNonceManager $dpopNonceManager,
        private readonly JsonEncoder $jsonEncoder,
        private readonly LoggerInterface $logger,
        private readonly AcceptLanguageMatcher $acceptLanguage,
        private readonly SelfRegistrationInterface $selfRegistration,
        #[Autowire(param: 'auth.spa_client_id')]
        private readonly string $spaClientId,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/auth/register',
        summary: 'Register a new user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string', example: 'Alice'),
                        'email' => new OA\Schema(
                            type: 'string',
                            format: 'email',
                            example: 'alice@example.com',
                        ),
                        'password' => new OA\Schema(type: 'string', example: '********'),
                    ],
                    required: ['name', 'email', 'password'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '202',
                description: 'Registration received. The answer and its timing are the same whether or not an account uses the address: a new account is created and emailed a verification link, while the owner of an existing account is emailed that someone tried to register with it.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Registration received. Check your email for the next step.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Bad request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Self-registration is closed; `details.reason` is `registration_closed`. Nothing was stored or sent.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $registerRequest, Request $request): JsonResponse
    {
        $command = new RegisterUserCommand(
            email: new Email($registerRequest->email),
            name: $registerRequest->name,
            plainPassword: $registerRequest->password,
            browserLanguage: $this->acceptLanguage->match($request->headers->get('Accept-Language')),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.registration_received', domain: 'auth'),
        ], Response::HTTP_ACCEPTED);
    }

    #[OA\Operation\Get(
        path: '/api/auth/registration',
        summary: 'Whether self-registration is open',
        description: 'Lets the sign-up page show whether it takes registrations. Operators open and close self-registration with the `auth.registration_open` server setting.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'The current state',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'open' => new OA\Schema(type: 'boolean', example: false),
                                ],
                                required: ['open'],
                            ),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/registration', name: 'registration', methods: ['GET'])]
    public function registration(): JsonResponse
    {
        return $this->successResponse(['open' => $this->selfRegistration->isOpen()]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/logout',
        summary: 'End the session: revoke the chain of the presented access token',
        responses: [
            new OA\Response(
                response: '200',
                description: 'The access token, its refresh token and every token of their chain are revoked; WebSocket connections opened with them are closed at once, or by the next socket reconciliation when the web server could not close them now',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Token revoked.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The chain could not be revoked; the tokens are still live, so send the logout again after the Retry-After delay.',
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
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // OAuth2Authenticator copies the presenting token's identifier here. Its chain is
        // the session: logout revokes the whole chain and closes the sockets it opened.
        $accessTokenId = $request->attributes->get(AccessTokenAttributes::TOKEN_ID);
        if (!is_string($accessTokenId) || $accessTokenId === '') {
            return $this->unauthorized();
        }

        try {
            $this->commandBus->dispatch(new RevokeTokenCommand(
                tokenId: $accessTokenId,
                revokeChain: true,
            ));
        } catch (\Throwable $e) {
            // An unknown or already revoked token is answered 200 by the handler itself
            // (RFC 7009 section 2.2), and a socket the server could not close is left to
            // the reconciliation. A fault here means the chain was not revoked: 503 with
            // Retry-After has the client send the logout again; the revocation is idempotent.
            $this->logger->error('Logout could not revoke the token chain.', [
                'exception' => HandlerFailure::cause($e),
            ]);

            return $this->serviceUnavailable();
        }

        return $this->successResponse([
            'message' => $this->trans('success.token_revoked', domain: 'auth'),
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/refresh',
        summary: 'Refresh an access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'refreshToken' => new OA\Schema(type: 'string', example: 'your-refresh-token'),
                    ],
                    required: ['refreshToken'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Token refreshed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'accessToken' => new OA\Schema(type: 'string'),
                                    'tokenType' => new OA\Schema(type: 'string', example: 'Bearer'),
                                    'expiresIn' => new OA\Schema(type: 'integer', example: 3600),
                                    'refreshToken' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'The refresh token is unknown, malformed, revoked, expired or replayed, is bound to another proof key, or belongs to a disabled account or a revoked client; sign in again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The server could not decide on the token, for example while its database restarts or the DPoP nonce store is unavailable; the token may still be valid, so retry after the Retry-After delay. Carries no DPoP-Nonce header.',
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
    )]
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(#[MapRequestPayload] RefreshTokenRequest $payload, Request $request): JsonResponse
    {
        // Validate DPoP proof
        $dpopHeader = $request->headers->get('DPoP');
        if ($dpopHeader === null || $dpopHeader === '') {
            return $this->errorResponse('DPoP proof header is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
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
        } catch (DpopStoreUnavailableException) {
            // Not a verdict on the proof: the store that logged the failure could not answer.
            // The token stays unconsumed, so the client waits and presents it again.
            return $this->serviceUnavailable();
        }

        $command = new RefreshTokenCommand(
            refreshTokenId: $payload->refreshToken,
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            dpopJkt: $result->getJkt(),
            // Only the first-party client's tokens rotate here; any other client
            // refreshes at the token endpoint with its own client authentication.
            clientId: PublicId::fromString($this->spaClientId),
        );

        try {
            $envelope = $this->commandBus->dispatch($command);
            $stamp = $envelope->last(HandledStamp::class);
            $tokenResponse = $stamp?->getResult();
        } catch (\Throwable $e) {
            return $this->refreshFailure($e);
        }

        $response = $this->successResponse(TokenResource::from($tokenResponse));
        $this->dpopNonceManager->attachNextNonce($response);

        return $response;
    }

    #[OA\Operation\Post(
        path: '/api/auth/password/reset-request',
        summary: 'Request a password reset email',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'email' => new OA\Schema(
                            type: 'string',
                            format: 'email',
                            example: 'alice@example.com',
                        ),
                    ],
                    required: ['email'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Request accepted. The answer is the same whether or not an account uses the address; an existing account is emailed a reset link.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'If the email exists, a password reset link has been sent.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many password reset requests from this IP',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
        security: [],
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
        } catch (\Throwable $e) {
            if (HandlerFailure::cause($e) instanceof RateLimiterUnavailableException) {
                throw $e;
            }
            // Always return 200 to avoid revealing whether the email exists.
            $this->logger->error('Password reset request failed.', [
                'exception' => $e,
            ]);
        }

        // Always return 200 to avoid revealing whether the email exists
        return $this->successResponse([
            'message' => $this->trans('success.password_reset_sent', domain: 'auth'),
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/password/reset',
        summary: 'Set a new password with a password reset token',
        description: 'Redeems the token once and signs the account out of every session.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ResetPasswordRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Password reset',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Your password has been reset.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The token is unknown, expired or already used',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many password reset attempts from this IP',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/password/reset', name: 'password_reset', methods: ['POST'])]
    public function resetPassword(#[MapRequestPayload] ResetPasswordRequest $payload): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ResetPasswordCommand(token: $payload->token, password: $payload->password));
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.password_reset', domain: 'auth'),
        ]);
    }

    #[OA\Operation\Get(
        path: '/api/auth/me',
        summary: 'Get the current authenticated user profile',
        responses: [
            new OA\Response(
                response: '200',
                description: 'User profile: the full profile for a first-party session, and for a delegated token the public fields plus the email fields when it carries the email scope',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                oneOf: [
                                    new OA\Schema(ref: UserResource::class),
                                    new OA\Schema(
                                        description: 'The profile a delegated token reads',
                                        type: 'object',
                                        properties: [
                                            'publicId' => new OA\Schema(
                                                description: 'Public identifier',
                                                type: 'string',
                                                minLength: PublicId::LENGTH,
                                                maxLength: PublicId::LENGTH,
                                                pattern: PublicId::SCHEMA_PATTERN,
                                            ),
                                            'name' => new OA\Schema(description: 'Display name', type: 'string'),
                                            'email' => new OA\Schema(
                                                description: 'Email address; only with the email scope',
                                                type: 'string',
                                                format: 'email',
                                            ),
                                            'emailVerifiedAt' => new OA\Schema(
                                                description: 'When the email was verified; only with the email scope',
                                                type: 'string',
                                                format: 'date-time',
                                                nullable: true,
                                            ),
                                            'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                        ],
                                        required: ['publicId', 'name', 'createdAt'],
                                        additionalProperties: false,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $attributes = $request->attributes;

        return $this->successResponse(AccessTokenAttributes::isFirstParty($attributes->get(AccessTokenAttributes::FIRST_PARTY))
            ? UserResource::from($user)
            : UserResource::forDelegatedToken($user, AccessTokenAttributes::scopes($attributes->get(AccessTokenAttributes::SCOPES))));
    }

    #[OA\Operation\Put(
        path: '/api/auth/me',
        summary: 'Update the current user profile',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string', example: 'Alice Johnson'),
                    ],
                    required: ['name'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Profile updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(type: 'string'),
                                    'name' => new OA\Schema(type: 'string'),
                                    'email' => new OA\Schema(type: 'string', format: 'email'),
                                    'emailVerifiedAt' => new OA\Schema(
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true,
                                    ),
                                    'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    'signal' => new OA\Schema(
                                        description: 'WebAuthn CurrentUserDetails signal for updating the passkey picker',
                                        type: 'object',
                                        nullable: true,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'User not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
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
            $this->logger->error('Profile update failed.', [
                'exception' => $e,
            ]);

            return $this->errorResponse(
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // Send CurrentUserDetails signal so the client updates the passkey picker
        try {
            $signal = $this->passkeyService->createCurrentUserDetailsSignal(
                $user->getPublicId()->toString(),
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

    #[OA\Operation\Post(
        path: '/api/auth/email/verify',
        summary: 'Verify an email address with a token',
        description: 'Redeems the token from a verification email once, in a signed-in session of the account it was sent for. The token verifies only the address it was sent to, while the account still has that address. A token for a pending email change makes that address the account\'s address and signs the account out of every other session; the session that made the request stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: VerifyEmailRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Email address verified',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Email verified.'),
                                    'emailChanged' => new OA\Schema(
                                        description: 'True when the link confirmed a pending email change: the account now uses the new address, and every other session was signed out.',
                                        type: 'boolean',
                                    ),
                                ],
                                required: ['message', 'emailChanged'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The token is unknown, expired, already used or for an address the account no longer has',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated, or another change ended this session first (reason credentials_changed); the token stays usable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'The token is valid but was sent for another account (`details.reason` `verification_link_other_account`), or the request used a delegated access token; the token stays usable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many verification attempts from this IP',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/email/verify', name: 'email_verify', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        try {
            $emailChanged = $this->commandBus->dispatch(new VerifyEmailCommand(
                token: $payload->token,
                userId: $user->getId()->toString(),
                // OAuth2Authenticator copies the presenting token's identifier here; when the link
                // confirms an email change, its session stays signed in.
                currentAccessTokenId: $request->attributes->get(AccessTokenAttributes::TOKEN_ID) |> AccessTokenAttributes::tokenId(...),
            ))->last(HandledStamp::class)?->getResult() === true;
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.email_verified', domain: 'auth'),
            'emailChanged' => $emailChanged,
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/me/email/verification',
        summary: 'Send a new verification email',
        description: 'Emails the current user a new verification link and ends the one sent earlier. The answer is the same whether or not an email was sent: nothing is sent when the address is already verified or the user has used up their resend allowance.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Request accepted',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(
                                        type: 'string',
                                        example: 'If your email address still needs verification, a new link has been sent.',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many verification requests from this IP',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/me/email/verification', name: 'me_email_verification', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function resendEmailVerification(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        try {
            $this->commandBus->dispatch(new ResendEmailVerificationCommand($user->getId()->toString()));
        } catch (\Throwable $e) {
            if (HandlerFailure::cause($e) instanceof RateLimiterUnavailableException) {
                throw $e;
            }
            // The answer stays the same; the user can ask again.
            $this->logger->error('Verification email resend failed.', [
                'exception' => $e,
            ]);
        }

        return $this->successResponse([
            'message' => $this->trans('email_verification.resend_sent', domain: 'auth'),
        ]);
    }

    #[OA\Operation\Put(
        path: '/api/auth/me/email',
        summary: 'Ask to change the current user email',
        description: 'Needs the current password, plus a current TOTP code when TOTP is on; a wrong password and a missing or wrong code get the same 422 with `details.reason` `step_up_failed`. The new address is held as `pendingEmail` and sent a verification link; the account keeps its address until that link is used. The answer is the same whether or not another account uses the new address: the link then fails. Asking for the current address drops a pending change.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ChangeEmailRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '202',
                description: 'Change received; a verification link is on its way to the new address',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Check the new address for a link to confirm the change.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, or the password or code is wrong',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many step-up attempts for this account',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/me/email', name: 'me_email', methods: ['PUT'])]
    public function changeEmail(#[MapRequestPayload] ChangeEmailRequest $payload): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        try {
            $this->commandBus->dispatch(new ChangeOwnEmailCommand(
                userId: $user->getId()->toString(),
                email: $payload->email,
                currentPassword: $payload->currentPassword,
                totpCode: $payload->totpCode,
            ));
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.email_change_requested', domain: 'auth'),
        ], Response::HTTP_ACCEPTED);
    }

    #[OA\Operation\Put(
        path: '/api/auth/me/password',
        summary: 'Change the current user password',
        description: 'Needs the current password, plus a current TOTP code when TOTP is on; a wrong password and a missing or wrong code get the same 422 with `details.reason` `step_up_failed`. A new password outside the length policy is refused before any step-up attempt is spent. Signs the account out of every other session, paired devices and third-party apps included; the session that made the request stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ChangePasswordRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Password changed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Password changed.'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated, or another change ended this session first (reason credentials_changed); nothing was changed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, or the password or code is wrong',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many step-up attempts for this account',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/me/password', name: 'me_password', methods: ['PUT'])]
    public function changePassword(#[MapRequestPayload] ChangePasswordRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        try {
            $this->commandBus->dispatch(new ChangePasswordCommand(
                userId: $user->getId()->toString(),
                currentPassword: $payload->currentPassword,
                newPassword: $payload->newPassword,
                totpCode: $payload->totpCode,
                // OAuth2Authenticator copies the presenting token's identifier here; its session stays signed in.
                currentAccessTokenId: $request->attributes->get(AccessTokenAttributes::TOKEN_ID) |> AccessTokenAttributes::tokenId(...),
            ));
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.password_changed', domain: 'auth'),
        ]);
    }

    private function getCurrentUser(): ?User
    {
        $securityUser = $this->security->getUser();
        if (!$securityUser instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $this->userService->findByUuid(
            Uuid::fromString($securityUser->getId()),
        );
    }

    /**
     * A refused grant answers 401, after which the client signs in again. Any other
     * failure, such as a database that is restarting, says nothing about the token: it
     * answers 503 with Retry-After so the client keeps its session and retries.
     */
    private function refreshFailure(\Throwable $exception): JsonResponse
    {
        $cause = $exception instanceof HandlerFailedException
            ? $exception->getPrevious() ?? $exception
            : $exception;

        // A revoked client is reported as invalid_client, the only protocol error of the grant.
        if ($cause instanceof RefreshTokenRejectedException || $cause instanceof OAuthProtocolException) {
            $this->logger->info('Token refresh rejected: {reason}', ['reason' => $cause->getMessage()]);

            return $this->errorResponse($this->trans('errors.invalid_token', domain: 'auth'), Response::HTTP_UNAUTHORIZED);
        }

        $this->logger->error('Token refresh failed.', ['exception' => $cause]);

        return $this->serviceUnavailable();
    }

    /** 503 with Retry-After: the client keeps its session and retries; nothing was decided about the token. */
    private function serviceUnavailable(): JsonResponse
    {
        $response = $this->errorResponse($this->trans('errors.internal', domain: 'auth'), Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', (string) DpopStoreUnavailableException::RETRY_AFTER_SECONDS);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Map a command-bus exception to a safe translated API error response.
     *
     * Logs the full stack trace at the controller edge so internal details are
     * never leaked to clients.
     */
    private function handleCommandException(\Throwable $exception, string $fallbackMessage, int $fallbackStatus): JsonResponse
    {
        $previous = $exception instanceof HandlerFailedException
            ? $exception->getPrevious()
            : $exception;

        // Shared outcomes, answered by the exception subscribers: 401, 422, 429 and 503.
        if ($previous instanceof StepUpFailedException
            || $previous instanceof StepUpRateLimitedException
            || $previous instanceof CredentialsChangedException
            || $previous instanceof RateLimiterUnavailableException) {
            throw $previous;
        }

        if ($previous instanceof RegistrationClosedException) {
            return $this->errorResponse(
                $this->trans('errors.registration_closed', domain: 'auth'),
                Response::HTTP_FORBIDDEN,
                ['reason' => RegistrationClosedException::REASON],
            );
        }

        if ($previous instanceof PasswordResetException) {
            return $this->errorResponse(
                $this->trans('errors.password_reset_failed', domain: 'auth'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($previous instanceof PasswordPolicyException) {
            return $this->errorResponse($previous->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($previous instanceof EmailVerificationException) {
            return $this->errorResponse(
                $this->trans('errors.email_verification_failed', domain: 'auth'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($previous instanceof VerificationLinkOtherAccountException) {
            return $this->errorResponse(
                $this->trans('errors.verification_link_other_account', domain: 'auth'),
                Response::HTTP_FORBIDDEN,
                ['reason' => VerificationLinkOtherAccountException::REASON],
            );
        }

        if ($previous instanceof \InvalidArgumentException) {
            return $this->errorResponse(
                $this->trans('errors.invalid_request', domain: 'auth'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->logger->error('Auth controller command failed.', [
            'exception' => $previous ?? $exception,
        ]);

        return $this->errorResponse($fallbackMessage, $fallbackStatus);
    }
}
