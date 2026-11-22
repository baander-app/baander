<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\Command\Passkey\DeletePasskeyCommand;
use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\DTO\AuthenticatedPasskeyOwner;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Passkey\DeletePasskeyRequest;
use App\Auth\Interface\Request\Passkey\PasskeyRegistrationOptionsRequest;
use App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest;
use App\Auth\Interface\Request\Passkey\VerifyPasskeyChallengeRequest;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth/passkey', name: 'passkey_')]
final class PasskeyController
{
    use ApiResponsesTrait;

    private const string INVALID_CHALLENGE = 'invalid_challenge';
    private const string REGISTRATION_FAILED = 'passkey_registration_failed';
    private const string CHECK_FAILED = 'passkey_auth_failed';

    public function __construct(
        private readonly Security $security,
        private readonly PasskeyService $passkeyService,
        private readonly MessageBusInterface $commandBus,
        private readonly PasskeyRepositoryInterface $passkeyRepository,
        private readonly JsonEncoder $jsonEncoder,
        private readonly UserPortInterface $users,
        private readonly StepUpVerifier $stepUp,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/auth/passkey',
        summary: 'List registered passkeys for the current user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of passkeys',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'publicId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'name' => new OA\Schema(type: 'string'),
                                        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                        'lastUsedAt' => new OA\Schema(
                                            type: 'string',
                                            format: 'date-time',
                                            nullable: true,
                                        ),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userUuid = Uuid::fromString($user->getId());
        $passkeys = $this->passkeyRepository->forUser($userUuid);

        $items = array_map(static fn (Passkey $passkey) => [
            'publicId' => $passkey->getPublicId()->toString(),
            'name' => $passkey->getName(),
            'createdAt' => $passkey->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $passkey->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ], $passkeys);

        usort($items, static fn (array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);

        return $this->successResponse($items);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/options',
        summary: 'Get WebAuthn registration options',
        description: 'Needs a verified email address, answered otherwise with 403 and `details.reason` `email_unverified`. Needs the current password, plus a current TOTP code when TOTP is on; a wrong password and a missing or wrong code get the same 422 with `details.reason` `step_up_failed`. The challenge is bound to the signed-in user and expires after 5 minutes.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: PasskeyRegistrationOptionsRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Registration options',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(description: 'WebAuthn PublicKeyCredentialCreationOptions', type: 'object'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'The email address is unverified (`details.reason` `email_unverified`)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, or the password or code is wrong',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many step-up attempts for this account',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The passkey challenge store is unavailable; retry after the Retry-After delay.',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        description: 'Seconds to wait before retrying',
                        schema: new OA\Schema(type: 'integer', example: 1),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/options', name: 'options', methods: ['POST'])]
    public function registrationOptions(#[MapRequestPayload(mapWhenEmpty: true)] PasskeyRegistrationOptionsRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userUuid = Uuid::fromString($user->getId());
        $owner = $this->users->findByUuid($userUuid);
        if ($owner === null) {
            return $this->notFound();
        }
        // Before step-up, so an unverified account spends none of its attempts.
        EmailUnverifiedException::unlessVerified($owner);
        $this->stepUp->verify($owner, $payload->currentPassword, $payload->totpCode);
        $userId = $owner->getPublicId()->toString();
        $existingPasskeys = $this->passkeyRepository->forUser($userUuid);
        $existingCredentialIds = array_map(
            static fn ($passkey) => $passkey->getCredentialId(),
            $existingPasskeys,
        );

        try {
            $result = $this->passkeyService->createRegistrationOptions(
                userId: $userId,
                username: $user->getEmail(),
                existingCredentialIds: $existingCredentialIds,
            );
        } catch (PasskeyChallengeUnavailableException) {
            return $this->challengeStoreUnavailable();
        }

        return $this->successResponse($result);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/register',
        summary: 'Register a new passkey',
        description: 'Signs the account out of every other session, paired devices and third-party apps included; the session that completes the registration stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'challengeKey' => new OA\Schema(type: 'string', example: 'challenge-key-abc123'),
                        'response' => new OA\Schema(
                            description: 'WebAuthn authenticator response (base64-encoded attestation object)',
                            type: 'object',
                        ),
                        'name' => new OA\Schema(type: 'string', example: 'Passkey'),
                    ],
                    required: ['challengeKey', 'response'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Passkey registered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'credentialId' => new OA\Schema(type: 'string'),
                                    'name' => new OA\Schema(type: 'string', example: 'Passkey'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated, or another change ended this session first (reason credentials_changed); nothing was stored',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The challenge is unknown, spent or issued to another user (`details.reason` `invalid_challenge`), or the ceremony was refused (`details.reason` `passkey_registration_failed`); why it was refused is logged, not returned',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The passkey challenge store is unavailable; retry after the Retry-After delay.',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        description: 'Seconds to wait before retrying',
                        schema: new OA\Schema(type: 'integer', example: 1),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterPasskeyRequest $passkeyRequest, Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $owner = $this->users->findByUuid(Uuid::fromString($user->getId()));
        if ($owner === null) {
            return $this->notFound();
        }

        try {
            $expectedOptions = $this->passkeyService->consumeRegistrationChallenge(
                $owner->getPublicId()->toString(),
                $passkeyRequest->challengeKey,
            );
        } catch (PasskeyChallengeUnavailableException) {
            return $this->challengeStoreUnavailable();
        } catch (\Throwable) {
            return $this->errorResponse(
                $this->trans('errors.invalid_challenge', domain: 'auth'),
                details: ['reason' => self::INVALID_CHALLENGE],
            );
        }

        try {
            $credentialRecord = $this->passkeyService->verifyRegistrationResponse(
                $passkeyRequest->response,
                $expectedOptions,
            );
        } catch (\Throwable $exception) {
            return $this->registrationRefused($exception);
        }

        $credentialId = self::base64UrlEncode($credentialRecord->publicKeyCredentialId);

        $command = new RegisterPasskeyCommand(
            userId: $user->getId(),
            name: $passkeyRequest->name,
            credentialId: $credentialId,
            credentialRecordData: $this->passkeyService->credentialRecordToArray($credentialRecord),
            counter: $credentialRecord->counter,
            currentAccessTokenId: self::currentAccessTokenId($request),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $exception) {
            // Answered by CredentialsChangedListener: 401, so the client signs in again.
            if (HandlerFailure::cause($exception) instanceof CredentialsChangedException) {
                throw $exception;
            }

            return $this->registrationRefused($exception);
        }

        return $this->successResponse([
            'credentialId' => $credentialId,
            'name' => $passkeyRequest->name,
        ], Response::HTTP_CREATED);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/authenticate/options',
        summary: 'Get WebAuthn authentication options',
        description: 'Passkey sign-in is discoverable: the options name no account and list no credential, so the authenticator offers the passkeys it holds for this relying party. The challenge is spent by the first sign-in or check that presents it and expires after 5 minutes.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication options',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(description: 'WebAuthn PublicKeyCredentialRequestOptions', type: 'object'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The passkey challenge store is unavailable; retry after the Retry-After delay.',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        description: 'Seconds to wait before retrying',
                        schema: new OA\Schema(type: 'integer', example: 1),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/authenticate/options', name: 'authenticate_options', methods: ['POST'])]
    public function authenticationOptions(): JsonResponse
    {
        try {
            $result = $this->passkeyService->createAuthenticationOptions();
        } catch (PasskeyChallengeUnavailableException) {
            return $this->challengeStoreUnavailable();
        }

        return $this->successResponse($result);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/authenticate',
        summary: 'Check a passkey assertion without signing in',
        description: 'Runs the checks passkey sign-in runs and names the account the assertion proves, without issuing tokens; sign-in itself is `POST /api/auth/login/passkey`. The challenge is spent once; the signature counter must increase unless the authenticator keeps none; an account with TOTP on needs a passkey that verified the user; a disabled account is refused.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: VerifyPasskeyChallengeRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'The assertion holds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'userId' => new OA\Schema(
                                        description: 'Public ID of the account the passkey belongs to',
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                ],
                                required: ['userId'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'The assertion was refused. An unknown credential, a bad signature, a spent challenge and every other refusal get the same answer, with `details.reason` `passkey_auth_failed`.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The passkey challenge store is unavailable; retry after the Retry-After delay.',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        description: 'Seconds to wait before retrying',
                        schema: new OA\Schema(type: 'integer', example: 1),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
        security: [],
    )]
    #[Route('/authenticate', name: 'authenticate', methods: ['POST'])]
    public function authenticate(#[MapRequestPayload] VerifyPasskeyChallengeRequest $payload): JsonResponse
    {
        if (!is_array($payload->response)) {
            return $this->checkRefused();
        }

        try {
            $verified = $this->commandBus->dispatch(new AuthenticatePasskeyCommand(
                challengeKey: $payload->challengeKey,
                response: $payload->response,
            ))->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $exception) {
            if (HandlerFailure::cause($exception) instanceof PasskeyChallengeUnavailableException) {
                return $this->challengeStoreUnavailable();
            }
            $this->logger->debug('Passkey check refused.', ['exception' => $exception]);

            return $this->checkRefused();
        }

        // Passkey sign-in refuses a disabled account once the assertion holds, and so does the check.
        $owner = $verified instanceof AuthenticatedPasskeyOwner ? $this->users->findByUuid($verified->userId) : null;
        if ($owner === null || $owner->isDisabled()) {
            return $this->checkRefused();
        }

        return $this->successResponse(['userId' => $owner->getPublicId()->toString()]);
    }

    #[OA\Operation\Delete(
        path: '/api/auth/passkey/{publicId}',
        summary: 'Delete a passkey',
        description: 'Needs the current password, plus a current TOTP code when TOTP is on; a wrong password and a missing or wrong code get the same 422 with `details.reason` `step_up_failed`. An unknown passkey answers 404 before any step-up attempt is spent. Signs the account out of every other session, paired devices and third-party apps included; the session that made the request stays signed in.',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Passkey public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: DeletePasskeyRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Passkey deleted',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'Passkey deleted.'),
                                    'signal' => new OA\Schema(
                                        description: 'WebAuthn AllAcceptedCredentials signal for updating the passkey picker',
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
                description: 'Not authenticated, or another change ended this session first (reason credentials_changed); nothing was changed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Passkey not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, or the password or code is wrong',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'Too many step-up attempts for this account',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $publicId, #[MapRequestPayload(mapWhenEmpty: true)] DeletePasskeyRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        // A refused step-up, a missing passkey or a session another change ended propagates to
        // the exception listeners, which answer 422, 429, 404 or 401.
        $remainingCredentialIds = $this->commandBus->dispatch(new DeletePasskeyCommand(
            userId: $user->getId(),
            passkeyPublicId: $publicId,
            currentPassword: $payload->currentPassword,
            totpCode: $payload->totpCode,
            currentAccessTokenId: self::currentAccessTokenId($request),
        ))->last(HandledStamp::class)?->getResult();
        if (!is_array($remainingCredentialIds)) {
            throw new \LogicException('Deleting a passkey returned no remaining credentials.');
        }

        $userUuid = Uuid::fromString($user->getId());
        try {
            $owner = $this->users->findByUuid($userUuid) ?? throw new \UnexpectedValueException('User not found.');
            $signal = $this->passkeyService->createAllAcceptedCredentialsSignal(
                $owner->getPublicId()->toString(),
                $user->getEmail(),
                $remainingCredentialIds,
            );
            $signalData = $this->jsonEncoder->decode($signal, 'json');
        } catch (\UnexpectedValueException) {
            $signalData = null;
        }

        return $this->successResponse([
            'message' => $this->trans('success.passkey_deleted', domain: 'auth'),
            'signal' => $signalData,
        ]);
    }

    private function challengeStoreUnavailable(): JsonResponse
    {
        $response = $this->errorResponse(
            $this->trans('errors.passkey_unavailable', domain: 'auth'),
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->headers->set('Retry-After', (string) PasskeyChallengeUnavailableException::RETRY_AFTER_SECONDS);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Every refused registration gets one answer; why it was refused goes to the log only. */
    private function registrationRefused(\Throwable $reason): JsonResponse
    {
        $this->logger->info('Passkey registration refused.', ['exception' => $reason]);

        return $this->errorResponse(
            $this->trans('errors.passkey_registration_failed', domain: 'auth'),
            details: ['reason' => self::REGISTRATION_FAILED],
        );
    }

    /** Every refused check gets one answer, so it tells nobody which check refused it. */
    private function checkRefused(): JsonResponse
    {
        return $this->errorResponse(
            $this->trans('errors.passkey_auth_failed', domain: 'auth'),
            Response::HTTP_UNAUTHORIZED,
            ['reason' => self::CHECK_FAILED],
        );
    }

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }

    /** The access token the request was made with, whose session a passkey change keeps signed in. */
    private static function currentAccessTokenId(Request $request): ?string
    {
        return $request->attributes->get(AccessTokenAttributes::TOKEN_ID) |> AccessTokenAttributes::tokenId(...);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
