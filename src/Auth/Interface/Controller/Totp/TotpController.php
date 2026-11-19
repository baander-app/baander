<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Totp\DisableTotpRequest;
use App\Auth\Interface\Request\Totp\EnableTotpRequest;
use App\Auth\Interface\Request\Totp\StartTotpSetupRequest;
use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
        private readonly LoggerInterface $logger,
        private readonly string $appName,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/totp/setup',
        summary: 'Generate TOTP secret and provisioning URI',
        description: 'Refused while TOTP is on, and for an unverified email address with 422 and `details.reason` `email_unverified`. Needs the current password; a wrong one gets 422 with `details.reason` `step_up_failed`. Each setup replaces the pending secret of the previous one; it expires after 10 minutes.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: StartTotpSetupRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP provisioning data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'secret', type: 'string', example: 'JBSWY3DPEHPK3PXP'),
                            new OA\Property(property: 'provisioningUri', type: 'string', example: 'otpauth://totp/B%C3%A5nder:alice%40baander.app?secret=JBSWY3DPEHPK3PXP&issuer=B%C3%A5nder'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'TOTP is already on', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error, an unverified email address, or the password is wrong', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '429', description: 'Too many step-up attempts for this account', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Post(
        path: '/api/auth/totp/enable',
        summary: 'Enable TOTP after verifying a code from the authenticator app',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code'], properties: [
                        new OA\Property(property: 'code', type: 'string', example: '123456'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP enabled',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'TOTP enabled.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Invalid TOTP code or no pending setup', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'TOTP is already on', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/enable', name: 'enable', methods: ['POST'])]
    public function enable(#[MapRequestPayload] EnableTotpRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $command = new EnableTotpCommand(
            userId: $user->getId(),
            code: $payload->code,
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

    #[OA\Post(
        path: '/api/auth/totp/disable',
        summary: 'Disable TOTP (requires current TOTP code to verify)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code'], properties: [
                        new OA\Property(property: 'code', type: 'string', example: '123456'),
                    ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP disabled',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'TOTP disabled.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '400', description: 'Invalid TOTP code or TOTP not enabled', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(#[MapRequestPayload] DisableTotpRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $command = new DisableTotpCommand(
            userId: $user->getId(),
            code: $payload->code,
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

    /** A translated refusal the user can act on; anything else is logged and answered as a server fault. */
    private function refusal(\Throwable $exception): JsonResponse
    {
        $cause = $exception instanceof HandlerFailedException ? ($exception->getPrevious() ?? $exception) : $exception;
        // Shared outcomes, answered by the exception subscribers: 422, 429 and 503.
        if ($cause instanceof StepUpFailedException
            || $cause instanceof EmailUnverifiedException
            || $cause instanceof StepUpRateLimitedException
            || $cause instanceof RateLimiterUnavailableException) {
            throw $cause;
        }
        if (!$cause instanceof TotpChangeRefusedException) {
            $this->logger->error('TOTP command failed.', ['exception' => $cause]);

            return $this->errorResponse($this->trans('errors.internal', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->errorResponse(
            $this->trans($cause->refusal->translationKey(), domain: 'auth'),
            $cause->refusal === TotpChangeRefusal::AlreadyEnabled ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST,
        );
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
