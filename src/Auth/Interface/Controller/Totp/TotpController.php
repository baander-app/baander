<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Totp\DisableTotpRequest;
use App\Auth\Interface\Request\Totp\EnableTotpRequest;
use App\Auth\Interface\Request\Totp\StartTotpSetupRequest;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth/totp', name: 'totp_')]
final class TotpController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $commandBus,
        private readonly string $appName,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/auth/totp/setup',
        summary: 'Generate TOTP secret and provisioning URI',
        description: 'Refused while TOTP is on, and for an unverified email address with 422 and `details.reason` `email_unverified`. Needs the current password; a wrong one gets 422 with `details.reason` `step_up_failed`. Each setup replaces the pending secret of the previous one; it expires after 10 minutes.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: StartTotpSetupRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP provisioning data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'secret' => new OA\Schema(type: 'string', example: 'JBSWY3DPEHPK3PXP'),
                                    'provisioningUri' => new OA\Schema(
                                        type: 'string',
                                        example: 'otpauth://totp/B%C3%A5nder:alice%40baander.app?secret=JBSWY3DPEHPK3PXP&issuer=B%C3%A5nder',
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
                response: '409',
                description: 'TOTP is already on',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error, an unverified email address, or the password is wrong',
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
    #[Route('/setup', name: 'setup', methods: ['POST'])]
    public function setup(#[MapRequestPayload(mapWhenEmpty: true)] StartTotpSetupRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $secret = $this->commandBus->dispatch(new StartTotpSetupCommand($user->getId(), $payload->currentPassword))
                ->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }
        if (!is_string($secret)) {
            throw new \LogicException('TOTP setup returned no secret.');
        }

        return $this->successResponse([
            'secret' => $secret,
            'provisioningUri' => $this->buildProvisioningUri($secret, $user->getEmail()),
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/totp/enable',
        summary: 'Enable TOTP after verifying a code from the authenticator app',
        description: 'Signs the account out of every other session, paired devices and third-party apps included; the session that sent the code stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: EnableTotpRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP enabled',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'TOTP enabled.'),
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
                response: '400',
                description: 'Invalid TOTP code or no pending setup',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'TOTP is already on',
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
        ],
    )]
    #[Route('/enable', name: 'enable', methods: ['POST'])]
    public function enable(#[MapRequestPayload] EnableTotpRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $command = new EnableTotpCommand(
            userId: $user->getId(),
            code: $payload->code,
            currentAccessTokenId: self::currentAccessTokenId($request),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }

        return $this->successResponse([
            'message' => $this->trans('success.totp_enabled', domain: 'auth'),
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/auth/totp/disable',
        summary: 'Disable TOTP',
        description: 'Refused with 400 while TOTP is off, before any step-up attempt is spent. Needs the current password and a current TOTP code; a missing or wrong password or code gets the same 422 with `details.reason` `step_up_failed`. Signs the account out of every other session, paired devices and third-party apps included; the session that made the request stays signed in.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: DisableTotpRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP disabled',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'message' => new OA\Schema(type: 'string', example: 'TOTP disabled.'),
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
                response: '400',
                description: 'TOTP is not on',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The password or code is wrong',
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
    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(#[MapRequestPayload(mapWhenEmpty: true)] DisableTotpRequest $payload, Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $command = new DisableTotpCommand(
            userId: $user->getId(),
            currentPassword: $payload->currentPassword,
            totpCode: $payload->totpCode,
            currentAccessTokenId: self::currentAccessTokenId($request),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }

        return $this->successResponse([
            'message' => $this->trans('success.totp_disabled', domain: 'auth'),
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }

    /**
     * A translated refusal the user can act on. Anything else is answered by the exception
     * listeners: a failed or rate-limited step-up, an unverified address, a session another
     * change ended (401 credentials_changed), a lock wait that timed out (503 with Retry-After,
     * so the change can be retried) and, logged, any other fault.
     */
    private function refusal(\Throwable $exception): JsonResponse
    {
        $cause = HandlerFailure::cause($exception);
        if (!$cause instanceof TotpChangeRefusedException) {
            throw $cause;
        }

        return $this->errorResponse(
            $this->trans($cause->refusal->translationKey(), domain: 'auth'),
            $cause->refusal === TotpChangeRefusal::AlreadyEnabled ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST,
        );
    }

    /** The access token the request was made with, whose session a TOTP change keeps signed in. */
    private static function currentAccessTokenId(Request $request): ?string
    {
        return $request->attributes->get(AccessTokenAttributes::TOKEN_ID) |> AccessTokenAttributes::tokenId(...);
    }

    private function buildProvisioningUri(string $secret, string $email): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($this->appName),
            rawurlencode($email),
            $secret,
            rawurlencode($this->appName),
        );
    }
}
