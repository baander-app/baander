<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Request\Totp\DisableTotpRequest;
use App\Auth\Interface\Request\Totp\EnableTotpRequest;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/auth/totp', name: 'totp_')]
final class TotpController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    private const PENDING_SECRET_TTL = 600; // 10 minutes

    public function __construct(
        private readonly Security $security,
        private readonly TotpVerifierInterface $totpVerifier,
        private readonly CacheInterface $cache,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/totp/setup',
        summary: 'Generate TOTP secret and provisioning URI',
        responses: [
            new OA\Response(
                response: '200',
                description: 'TOTP provisioning data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'secret', type: 'string', example: 'JBSWY3DPEHPK3PXP'),
                            new OA\Property(property: 'provisioningUri', type: 'string', example: 'otpauth://totp/Baander:alice@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Baander'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/setup', name: 'setup', methods: ['POST'])]
    public function setup(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        // Generate a server-side TOTP secret (base32-encoded, suitable for OTPHP)
        $secret = $this->totpVerifier->generateSecret();
        $provisioningUri = $this->buildProvisioningUri($secret, $user->getEmail());

        // Store the pending secret in Redis with a 10-minute TTL
        $this->cache->get(
            $this->pendingSecretKey($user->getId()),
            fn () => $secret,
            self::PENDING_SECRET_TTL,
        );

        return $this->successResponse([
            'secret' => $secret,
            'provisioningUri' => $provisioningUri,
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
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage());
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
        } catch (ExceptionInterface|\Throwable $e) {
            return $this->errorResponse($e->getMessage());
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

    private function pendingSecretKey(string $userId): string
    {
        return sprintf('totp_pending_secret_%s', $userId);
    }

    private function buildProvisioningUri(string $secret, string $email): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode('Baander'),
            rawurlencode($email),
            $secret,
            rawurlencode('Baander'),
        );
    }
}
