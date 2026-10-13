<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\User;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Command\User\ResendEmailVerificationCommand;
use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\Exception\CurrentPasswordMismatchException;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
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
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
    use TranslatorTrait;

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
    public function register(#[MapRequestPayload] RegisterRequest $registerRequest, Request $request): JsonResponse
    {
        $command = new RegisterUserCommand(
            email: new Email($registerRequest->email),
            name: $registerRequest->name,
            plainPassword: $registerRequest->password,
            browserLanguage: $this->acceptLanguage->match($request->headers->get('Accept-Language')),
        );

        try {
            $envelope = $this->commandBus->dispatch($command);
            $stamp = $envelope->last(HandledStamp::class);
            $user = $stamp?->getResult();
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->successResponse(UserResource::from($user), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'End the session: revoke the chain of the presented access token',
        responses: [
            new OA\Response(
                response: '200',
                description: 'The access token, its refresh token and every token of their chain are revoked; WebSocket connections opened with them are closed',
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
            new OA\Response(
                response: '503',
                description: 'The chain could not be revoked, or the web server could not close its connections; the tokens may still be live, so send the logout again after the Retry-After delay.',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 5))],
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // OAuth2Authenticator copies the presenting token's identifier here. Its chain is
        // the session: logout revokes the whole chain and closes the sockets it opened.
        $accessTokenId = $request->attributes->get('oauth_access_token_id');
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
            // (RFC 7009 section 2.2). A fault here means the chain, or a socket opened with
            // it, may still be live: 503 with Retry-After has the client send the logout
            // again, and the chain revocation is idempotent.
            $this->logger->error('Logout could not revoke the token chain.', [
                'exception' => HandlerFailure::cause($e),
            ]);

            return $this->serviceUnavailable();
        }

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
            new OA\Response(response: '401', description: 'The refresh token is unknown, malformed, revoked, expired or replayed, is bound to another proof key, or belongs to a disabled account or a revoked client; sign in again', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(
                response: '503',
                description: 'The server could not decide on the token, for example while its database restarts or the DPoP nonce store is unavailable; the token may still be valid, so retry after the Retry-After delay. Carries no DPoP-Nonce header.',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 5))],
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
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
            // Not a verdict on the proof: the store that logged the failure could not answer
            // (R7). The token stays unconsumed, so the client waits and presents it again.
            return $this->serviceUnavailable();
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
        } catch (\Throwable $e) {
            return $this->refreshFailure($e);
        }

        $response = $this->successResponse(TokenResource::from($tokenResponse));
        $this->dpopNonceManager->attachNextNonce($response);

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
                description: 'Request accepted. The answer is the same whether or not an account uses the address; an existing account is emailed a reset link.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'If the email exists, a password reset link has been sent.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '429', description: 'Too many password reset requests from this IP', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
        } catch (\Throwable $e) {
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

    #[OA\Post(
        path: '/api/auth/password/reset',
        summary: 'Set a new password with a password reset token',
        description: 'Redeems the token once and signs the account out of every session.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ResetPasswordRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Password reset',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Your password has been reset.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'The token is unknown, expired or already used', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '429', description: 'Too many password reset attempts from this IP', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
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
        description: 'Redeems the token from a verification email once. The token verifies only the address it was sent to, while the account still has that address.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: VerifyEmailRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Email address verified',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Email verified.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'The token is unknown, expired, already used or for an address the account no longer has', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '429', description: 'Too many verification attempts from this IP', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/email/verify', name: 'email_verify', methods: ['POST'])]
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $payload): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new VerifyEmailCommand(token: $payload->token));
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->successResponse([
            'message' => $this->trans('success.email_verified', domain: 'auth'),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/me/email/verification',
        summary: 'Send a new verification email',
        description: 'Emails the current user a new verification link and ends the one sent earlier. The answer is the same whether or not an email was sent: nothing is sent when the address is already verified or the user has used up their resend allowance.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Request accepted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'If your email address still needs verification, a new link has been sent.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '429', description: 'Too many verification requests from this IP', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            // The answer stays the same; the user can ask again.
            $this->logger->error('Verification email resend failed.', [
                'exception' => $e,
            ]);
        }

        return $this->successResponse([
            'message' => $this->trans('email_verification.resend_sent', domain: 'auth'),
        ]);
    }

    #[OA\Put(
        path: '/api/auth/me/email',
        summary: 'Change the current user email',
        description: 'The new address starts unverified and is sent a verification link; links sent to the old address stop working.',
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

        try {
            $changed = $this->commandBus->dispatch(new ChangeEmailCommand($user->getId()->toString(), $payload->email))
                ->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $e) {
            return $this->handleCommandException(
                $e,
                $this->trans('errors.internal', domain: 'auth'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
        if (!$changed instanceof User) {
            throw new \LogicException('ChangeEmailCommand did not return the user.');
        }

        return $this->successResponse(UserResource::from($changed));
    }

    #[OA\Put(
        path: '/api/auth/me/password',
        summary: 'Change the current user password',
        description: 'Signs the account out of every other session; the session that made the request stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['currentPassword', 'newPassword'], properties: [
                        new OA\Property(property: 'currentPassword', type: 'string', example: '********'),
                        new OA\Property(property: 'newPassword', type: 'string', example: '********', maxLength: 255, minLength: 8),
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
    public function changePassword(#[MapRequestPayload] ChangePasswordRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->notFound();
        }

        // OAuth2Authenticator copies the presenting token's identifier here; its session stays signed in.
        $currentAccessTokenId = $request->attributes->get('oauth_access_token_id');

        try {
            $this->commandBus->dispatch(new ChangePasswordCommand(
                userId: $user->getId()->toString(),
                currentPassword: $payload->currentPassword,
                newPassword: $payload->newPassword,
                currentAccessTokenId: is_string($currentAccessTokenId) && $currentAccessTokenId !== '' ? $currentAccessTokenId : null,
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
        $message = $this->trans('errors.internal', domain: 'auth');

        // A revoked client is reported as invalid_client, the only protocol error of the grant.
        if ($cause instanceof RefreshTokenRejectedException || $cause instanceof OAuthProtocolException) {
            $this->logger->info('Token refresh rejected: {reason}', ['reason' => $cause->getMessage()]);

            return $this->errorResponse($message, Response::HTTP_UNAUTHORIZED);
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

        if ($previous instanceof PasswordResetException) {
            return $this->errorResponse(
                $this->trans('errors.password_reset_failed', domain: 'auth'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($previous instanceof CurrentPasswordMismatchException) {
            return $this->errorResponse(
                $this->trans('errors.current_password_incorrect', domain: 'auth'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($previous instanceof EmailAddressInUseException) {
            return $this->errorResponse($previous->getMessage(), Response::HTTP_CONFLICT);
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
