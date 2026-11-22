<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Controller;

use App\Discovery\Application\Command\CompletePairingCommand;
use App\Discovery\Application\Command\CreatePairingCodeCommand;
use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Interface\Request\CompletePairingRequest;
use App\Discovery\Interface\Request\CreatePairingCodeRequest;
use App\Discovery\Interface\Request\RegisterServerRequest;
use App\Discovery\Interface\Resource\PairingSessionResource;
use App\Discovery\Interface\Resource\ServerInstanceResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Discovery', description: 'Server discovery and pairing endpoints')]
#[Route('/api/discovery', name: 'discovery_')]
final class DiscoveryController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ServerInstancePortInterface $serverPort,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/discovery/register',
        summary: 'Register a self-hosted server',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'serverUrl' => new OA\Schema(type: 'string', format: 'uri'),
                        'name' => new OA\Schema(type: 'string'),
                        'version' => new OA\Schema(type: 'string'),
                    ],
                    required: ['serverUrl', 'name', 'version'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Registered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ServerInstanceResource::class),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:discovery:register')]
    public function register(#[MapRequestPayload] RegisterServerRequest $payload): JsonResponse
    {
        $envelope = $this->commandBus->dispatch(new RegisterServerCommand(
            serverUrl: $payload->serverUrl,
            name: $payload->name,
            version: $payload->version,
        ));
        $server = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse(ServerInstanceResource::from($server), Response::HTTP_CREATED);
    }

    #[OA\Operation\Post(
        path: '/api/discovery/pairing-code',
        summary: 'Create a pairing code for a server',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'serverPublicId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'method' => new OA\Schema(
                            type: 'string',
                            enum: ['qr_code', 'email_url', 'server_code'],
                        ),
                    ],
                    required: ['serverPublicId', 'method'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Created',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: PairingSessionResource::class),
                ),
            ),
        ],
    )]
    #[Route('/pairing-code', name: 'pairing_code', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function createPairingCode(#[MapRequestPayload] CreatePairingCodeRequest $payload): JsonResponse
    {
        $envelope = $this->commandBus->dispatch(new CreatePairingCodeCommand(
            serverPublicId: PublicId::fromString($payload->serverPublicId),
            method: AuthenticationMethod::from($payload->method),
        ));
        $session = $envelope->last(HandledStamp::class)?->getResult();

        return $this->created(PairingSessionResource::from($session));
    }

    #[OA\Operation\Post(
        path: '/api/discovery/qr-payload/{serverPublicId}',
        summary: 'Create a QR code pairing session and return its payload',
        parameters: [
            new OA\Parameter(
                name: 'serverPublicId',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Success',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'qrData' => new OA\Schema(type: 'string'),
                                    'pairingCode' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/qr-payload/{serverPublicId}', name: 'qr_payload', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function qrPayload(string $serverPublicId): JsonResponse
    {
        $server = $this->serverPort->findByPublicId(PublicId::fromString($serverPublicId));
        if ($server === null) {
            return $this->notFound('Server not found.');
        }

        $envelope = $this->commandBus->dispatch(new CreatePairingCodeCommand(
            serverPublicId: $server->getPublicId(),
            method: AuthenticationMethod::QrCode,
        ));
        $session = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse([
            'qrData' => $session->getQrPayload(),
            'pairingCode' => $session->getPairingCode()->toString(),
        ]);
    }

    #[OA\Operation\Post(
        path: '/api/discovery/complete-pairing',
        summary: 'Complete a pairing session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'pairingCode' => new OA\Schema(type: 'string'),
                        'serverPublicId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                    ],
                    required: ['pairingCode', 'serverPublicId'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Completed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'serverUrl' => new OA\Schema(type: 'string', format: 'uri'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/complete-pairing', name: 'complete_pairing', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function completePairing(#[MapRequestPayload] CompletePairingRequest $payload): JsonResponse
    {
        $envelope = $this->commandBus->dispatch(new CompletePairingCommand(
            pairingCode: $payload->pairingCode,
            serverPublicId: PublicId::fromString($payload->serverPublicId),
        ));
        $session = $envelope->last(HandledStamp::class)?->getResult();

        return $this->successResponse([
            'serverUrl' => $session->getServerUrl(),
        ]);
    }
}
