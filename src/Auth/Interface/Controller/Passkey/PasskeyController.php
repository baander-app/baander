<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest;
use App\Auth\Interface\Request\Passkey\VerifyPasskeyChallengeRequest;
use App\Auth\Interface\Request\Passkey\WebAuthnOptionsRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
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
    ) {
    }

    #[OA\Get(
        path: '/api/auth/passkey',
        summary: 'List registered passkeys for the current user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of passkeys',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'publicId', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            'publicId' => $passkey->getId()->toString(),
            'name' => $passkey->getName(),
            'createdAt' => $passkey->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $passkey->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ], $passkeys);

        usort($items, static fn (array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);

        return $this->successResponse($items);
    }

    #[OA\Post(
        path: '/api/auth/passkey/options',
        summary: 'Get WebAuthn registration options',
        security: [],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Registration options',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', description: 'WebAuthn PublicKeyCredentialCreationOptions', type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/options', name: 'options', methods: ['POST'])]
    public function registrationOptions(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = $user->getId();

        $userUuid = Uuid::fromString($userId);
        $existingPasskeys = $this->passkeyRepository->forUser($userUuid);
        $existingCredentialIds = array_map(
            static fn ($passkey) => $passkey->getCredentialId(),
            $existingPasskeys,
        );

        $result = $this->passkeyService->createRegistrationOptions(
            userId: $userId,
            username: $user->getEmail(),
            existingCredentialIds: $existingCredentialIds,
        );

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/auth/passkey/register',
        summary: 'Register a new passkey',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['challengeKey', 'response'], properties: [
                        new OA\Property(property: 'challengeKey', type: 'string', example: 'challenge-key-abc123'),
                        new OA\Property(property: 'response', description: 'WebAuthn authenticator response (base64-encoded attestation object)', type: 'object'),
                        new OA\Property(property: 'name', type: 'string', example: 'Passkey'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Passkey registered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'credentialId', type: 'string'),
                            new OA\Property(property: 'name', type: 'string', example: 'Passkey'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Invalid challenge or verification failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterPasskeyRequest $passkeyRequest): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $expectedOptions = $this->passkeyService->getChallenge($passkeyRequest->challengeKey);
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
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }

        return $this->successResponse([
            'credentialId' => $credentialId,
            'name' => $passkeyRequest->name,
        ], Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/auth/passkey/authenticate/options',
        summary: 'Get WebAuthn authentication options',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                        new OA\Property(property: 'userId', description: 'Optional user ID to get passkey options for', type: 'string'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication options',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', description: 'WebAuthn PublicKeyCredentialRequestOptions', type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/authenticate/options', name: 'authenticate_options', methods: ['POST'])]
    public function authenticationOptions(#[MapRequestPayload] WebAuthnOptionsRequest $payload): JsonResponse
    {
        $allowedCredentialIds = [];
        if ($payload->userId !== '') {
            try {
                $userUuid = Uuid::fromString($payload->userId);
                $passkeys = $this->passkeyRepository->forUser($userUuid);
                $allowedCredentialIds = array_map(
                    static fn ($passkey) => $passkey->getCredentialId(),
                    $passkeys,
                );
            } catch (\Throwable) {
                // If user not found, return empty list — conditional UI
            }
        }

        $result = $this->passkeyService->createAuthenticationOptions($allowedCredentialIds);

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/auth/passkey/authenticate',
        description: 'The main passkey login flow goes through PasskeyAuthenticator at /api/auth/login/passkey. This endpoint is an alternative for API-based flows where the frontend manages the ceremony.',
        summary: 'Authenticate with a passkey (API-based flow)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['challengeKey', 'response'], properties: [
                        new OA\Property(property: 'challengeKey', type: 'string', example: 'challenge-key-abc123'),
                        new OA\Property(property: 'response', description: 'WebAuthn authenticator response (base64-encoded assertion)', type: 'object'),
                        new OA\Property(property: 'userId', type: 'string', nullable: true),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'credentialId', type: 'string'),
                            new OA\Property(property: 'signCount', type: 'integer'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid challenge or verification failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '401', description: 'No passkey found for credential ID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/authenticate', name: 'authenticate', methods: ['POST'])]
    public function authenticate(#[MapRequestPayload] VerifyPasskeyChallengeRequest $payload): JsonResponse
    {
        try {
            $expectedOptions = $this->passkeyService->getChallenge($payload->challengeKey);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_challenge', domain: 'auth'));
        }

        $rawId = $payload->response['rawId'] ?? $payload->response['id'] ?? '';
        $credentialId = self::base64UrlEncode(base64_decode($rawId, true) ?: $rawId);

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

        $storedCredential = $this->passkeyService->credentialRecordFromArray(
            $passkey->getData(),
            $passkey->getCounter(),
        );

        try {
            $updatedCredential = $this->passkeyService->verifyAuthenticationResponse(
                $payload->response,
                $expectedOptions,
                $storedCredential,
            );
        } catch (\Throwable $e) {
            return $this->errorResponse($this->trans('errors.passkey_auth_failed', ['{details}' => $e->getMessage()], 'auth'), Response::HTTP_UNAUTHORIZED);
        }

        if ($updatedCredential->counter > $passkey->getCounter()) {
            $passkey->updateCounter($updatedCredential->counter);
        }

        $this->passkeyRepository->markUsed($passkey);

        $userId = $this->passkeyRepository->userIdForCredentialId($credentialId);
        if ($userId === null) {
            return $this->errorResponse($this->trans('errors.cannot_resolve_user', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse([
            'userId' => $userId->toString(),
            'credentialId' => $credentialId,
            'signCount' => $updatedCredential->counter,
        ]);
    }

    #[OA\Delete(
        path: '/api/auth/passkey/{publicId}',
        summary: 'Delete a passkey',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Passkey UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Passkey deleted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Passkey deleted.'),
                            new OA\Property(property: 'signal', description: 'WebAuthn AllAcceptedCredentials signal for updating the passkey picker', type: 'object', nullable: true),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Passkey not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            Uuid::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $userUuid = Uuid::fromString($user->getId());
        $passkeys = $this->passkeyRepository->forUser($userUuid);

        $targetPasskey = null;
        foreach ($passkeys as $passkey) {
            if ($passkey->getId()->toString() === $publicId) {
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
            $signal = $this->passkeyService->createAllAcceptedCredentialsSignal(
                $user->getId(),
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
