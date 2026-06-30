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
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Discovery', description: 'Server discovery and pairing endpoints')]
#[Route('/api/discovery', name: 'discovery_')]
final class DiscoveryController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ServerInstancePortInterface $serverPort,
        private readonly Security $security,
    ) {
    }

    #[OA\Post(
        path: '/api/discovery/register',
        summary: 'Register a self-hosted server',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['serverUrl', 'name', 'version'],
                    properties: [
                        new OA\Property(property: 'serverUrl', type: 'string', format: 'uri'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'version', type: 'string'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Registered', content: new OA\JsonContent(
                ref: new Model(type: ServerInstanceResource::class),
            )),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterServerRequest $payload): JsonResponse
    {
        $envelope = $this->commandBus->dispatch(new RegisterServerCommand(
            serverUrl: $payload->serverUrl,
            name: $payload->name,
            version: $payload->version,
            apiKey: bin2hex(random_bytes(32)),
        ));
        $server = $envelope->last(HandledStamp::class)?->getResult();

        return $this->created(ServerInstanceResource::from($server));
    }

    #[OA\Post(
        path: '/api/discovery/pairing-code',
        summary: 'Create a pairing code for a server',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['serverPublicId', 'method'],
                    properties: [
                        new OA\Property(property: 'serverPublicId', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'method', type: 'string', enum: ['qr_code', 'email_url', 'server_code']),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(
                ref: new Model(type: PairingSessionResource::class),
            )),
        ],
    )]
    #[Route('/pairing-code', name: 'pairing_code', methods: ['POST'])]
    public function createPairingCode(#[MapRequestPayload] CreatePairingCodeRequest $payload): JsonResponse
    {
        $envelope = $this->commandBus->dispatch(new CreatePairingCodeCommand(
            serverPublicId: PublicId::fromString($payload->serverPublicId),
            method: AuthenticationMethod::from($payload->method),
        ));
        $session = $envelope->last(HandledStamp::class)?->getResult();

        return $this->created(PairingSessionResource::from($session));
    }

    #[OA\Get(
        path: '/api/discovery/qr-payload/{serverPublicId}',
        summary: 'Get QR payload for a pending pairing session',
        parameters: [
            new OA\Parameter(name: 'serverPublicId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'qrData', type: 'string'),
                    new OA\Property(property: 'pairingCode', type: 'string'),
                ], type: 'object'),
            ])),
        ],
    )]
    #[Route('/qr-payload/{serverPublicId}', name: 'qr_payload', methods: ['GET'])]
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

    #[OA\Post(
        path: '/api/discovery/complete-pairing',
        summary: 'Complete a pairing session',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['pairingCode', 'serverPublicId'],
                    properties: [
                        new OA\Property(property: 'pairingCode', type: 'string'),
                        new OA\Property(property: 'serverPublicId', type: 'string', format: 'uuid'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Completed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'serverUrl', type: 'string', format: 'uri'),
                ], type: 'object'),
            ])),
        ],
    )]
    #[Route('/complete-pairing', name: 'complete_pairing', methods: ['POST'])]
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
