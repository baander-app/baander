<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Passkey\PasskeyRegistrationOptionsRequest;
use App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest;
use App\Auth\Interface\Request\Passkey\VerifyPasskeyChallengeRequest;
use App\Auth\Interface\Request\Passkey\WebAuthnOptionsRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth/passkey', name: 'passkey_')]
final class PasskeyController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly PasskeyService $passkeyService,
        private readonly MessageBusInterface $commandBus,
        private readonly PasskeyRepositoryInterface $passkeyRepository,
        private readonly JsonEncoder $jsonEncoder,
        private readonly UserPortInterface $users,
        private readonly StepUpVerifier $stepUp,
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
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    properties: [
                                        'publicId' => new OA\Schema(
                                            type: 'string',
                                            minLength: 21,
                                            maxLength: 21,
                                            pattern: '^[0-9a-zA-Z_-]{21}$',
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
        description: 'Needs a verified email address, answered otherwise with 422 and `details.reason` `email_unverified`. Needs the current password, plus a current TOTP code when TOTP is on; a wrong password and a missing or wrong code get the same 422 with `details.reason` `step_up_failed`. The challenge is bound to the signed-in user and expires after 5 minutes.',
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
                response: '422',
                description: 'Validation error, an unverified email address, or the password or code is wrong',
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
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
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
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid challenge or verification failed',
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
    public function register(#[MapRequestPayload] RegisterPasskeyRequest $passkeyRequest): JsonResponse
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
            return $this->errorResponse($this->trans('errors.invalid_challenge', domain: 'auth'));
        }

        try {
            $credentialRecord = $this->passkeyService->verifyRegistrationResponse(
                $passkeyRequest->response,
                $expectedOptions,
            );
        } catch (\Throwable $e) {
            return $this->errorResponse($this->trans('errors.passkey_registration_failed', ['{details}' => $e->getMessage()], 'auth'));
        }

        $credentialId = self::base64UrlEncode($credentialRecord->publicKeyCredentialId);

        $command = new RegisterPasskeyCommand(
            userId: $user->getId(),
            name: $passkeyRequest->name,
            credentialId: $credentialId,
            credentialRecordData: $this->passkeyService->credentialRecordToArray($credentialRecord),
            counter: $credentialRecord->counter,
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }

        return $this->successResponse([
            'credentialId' => $credentialId,
            'name' => $passkeyRequest->name,
        ], Response::HTTP_CREATED);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/authenticate/options',
        summary: 'Get WebAuthn authentication options',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'userId' => new OA\Schema(description: 'Optional user public ID to get passkey options for', type: 'string'),
                    ],
                ),
            ),
        ),
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
    #[Route('/authenticate/options', name: 'authenticate_options', methods: ['POST'])]
    public function authenticationOptions(#[MapRequestPayload] WebAuthnOptionsRequest $payload): JsonResponse
    {
        $allowedCredentialIds = [];
        if ($payload->userId !== '') {
            try {
                $owner = $this->users->findByPublicId(PublicId::fromString($payload->userId));
                $passkeys = $owner === null ? [] : $this->passkeyRepository->forUser($owner->getId());
                $allowedCredentialIds = array_map(
                    static fn ($passkey) => $passkey->getCredentialId(),
                    $passkeys,
                );
            } catch (\Throwable) {
                // If user not found, return empty list — conditional UI
            }
        }

        try {
            $result = $this->passkeyService->createAuthenticationOptions($allowedCredentialIds);
        } catch (PasskeyChallengeUnavailableException) {
            return $this->challengeStoreUnavailable();
        }

        return $this->successResponse($result);
    }

    #[OA\Operation\Post(
        path: '/api/auth/passkey/authenticate',
        summary: 'Authenticate with a passkey (API-based flow)',
        description: 'The main passkey login flow goes through PasskeyAuthenticator at /api/auth/login/passkey. This endpoint is an alternative for API-based flows where the frontend manages the ceremony.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'challengeKey' => new OA\Schema(type: 'string', example: 'challenge-key-abc123'),
                        'response' => new OA\Schema(
                            description: 'WebAuthn authenticator response (base64-encoded assertion)',
                            type: 'object',
                        ),
                        'userId' => new OA\Schema(type: 'string', nullable: true),
                    ],
                    required: ['challengeKey', 'response'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'userId' => new OA\Schema(
                                        type: 'string',
                                        minLength: 21,
                                        maxLength: 21,
                                        pattern: '^[0-9a-zA-Z_-]{21}$',
                                    ),
                                    'credentialId' => new OA\Schema(type: 'string'),
                                    'signCount' => new OA\Schema(type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid challenge or verification failed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'No passkey found for credential ID',
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
    #[Route('/authenticate', name: 'authenticate', methods: ['POST'])]
    public function authenticate(#[MapRequestPayload] VerifyPasskeyChallengeRequest $payload): JsonResponse
    {
        try {
            $expectedOptions = $this->passkeyService->consumeLoginChallenge($payload->challengeKey);
        } catch (PasskeyChallengeUnavailableException) {
            return $this->challengeStoreUnavailable();
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_challenge', domain: 'auth'));
        }

        $rawId = $payload->response['rawId'] ?? $payload->response['id'] ?? '';
        $credentialId = self::base64UrlEncode(base64_decode(strtr($rawId, '-_', '+/'), true) ?: $rawId);

        $passkey = $this->passkeyRepository->ofCredentialId($credentialId);

        if ($passkey === null) {
            try {
                $signal = $this->passkeyService->createUnknownCredentialSignal($credentialId);
                $signalData = $this->jsonEncoder->decode($signal, 'json');
            } catch (\UnexpectedValueException) {
                $signalData = null;
            }

            return $this->errorResponse(
                $this->trans('errors.no_passkey_found', domain: 'auth'),
                Response::HTTP_UNAUTHORIZED,
                array_filter(['signal' => $signalData]),
            );
        }

        $userId = $this->passkeyRepository->userIdForCredentialId($credentialId);
        $owner = $userId === null ? null : $this->users->findByUuid($userId);
        if ($owner === null || !hash_equals($owner->getPublicId()->toString(), (string) ($passkey->getData()['userHandle'] ?? ''))
            || ($payload->userId !== '' && $payload->userId !== $owner->getPublicId()->toString())) {
            return $this->errorResponse($this->trans('errors.passkey_auth_failed', ['{details}' => 'Invalid credential owner.'], 'auth'), Response::HTTP_UNAUTHORIZED);
        }

        $storedCredential = $this->passkeyService->credentialRecordFromArray(
            $passkey->getData(),
            $passkey->getCounter(),
        );

        try {
            $updatedCredential = $this->passkeyService->verifyAuthenticationResponse(
                $payload->response,
                $expectedOptions,
                $storedCredential,
                $owner->hasTotp(),
            );
        } catch (\Throwable $e) {
            return $this->errorResponse($this->trans('errors.passkey_auth_failed', ['{details}' => $e->getMessage()], 'auth'), Response::HTTP_UNAUTHORIZED);
        }

        if ($updatedCredential->counter > $passkey->getCounter()) {
            $passkey->updateCounter($updatedCredential->counter);
        }

        $this->passkeyRepository->markUsed($passkey);

        return $this->successResponse([
            'userId' => $owner->getPublicId()->toString(),
            'credentialId' => $credentialId,
            'signCount' => $updatedCredential->counter,
        ]);
    }

    #[OA\Operation\Delete(
        path: '/api/auth/passkey/{publicId}',
        summary: 'Delete a passkey',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Passkey public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
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
                description: 'Not authenticated',
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
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $publicId): JsonResponse
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

        $userUuid = Uuid::fromString($user->getId());
        $passkeys = $this->passkeyRepository->forUser($userUuid);

        $targetPasskey = null;
        foreach ($passkeys as $passkey) {
            if ($passkey->getPublicId()->toString() === $publicId) {
                $targetPasskey = $passkey;
                break;
            }
        }

        if ($targetPasskey === null) {
            return $this->notFound();
        }

        try {
            $this->passkeyRepository->remove($targetPasskey);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.failed_delete_passkey', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $remainingPasskeys = $this->passkeyRepository->forUser($userUuid);
        $remainingCredentialIds = array_map(
            static fn ($passkey) => $passkey->getCredentialId(),
            $remainingPasskeys,
        );

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

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
