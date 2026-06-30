<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Interface\Resource\PartySessionResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Party', description: 'Watch party session endpoints')]
#[Route('/ws/party/{sessionPublicId}', name: 'party_websocket_')]
final class PartyWebSocketController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
    )
    {
    }

    #[OA\Get(
        path: '/ws/party/{sessionPublicId}',
        description: 'Returns session details and the WebSocket connection URL. Actual WebSocket handling is done through Swoole, not Symfony HTTP.',
        summary: 'Get WebSocket connection info for a party session',
        parameters: [
            new OA\Parameter(name: 'sessionPublicId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'WebSocket info',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'session', type: 'object'),
                        new OA\Property(property: 'wsUrl', type: 'string', example: 'wss://example.com/ws/party/{publicId}'),
                    ],
                ),
            ),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'info', methods: ['GET'])]
    public function info(string $sessionPublicId, Request $request): JsonResponse
    {
        $session = $this->sessionPort->findByPublicId(
            PublicId::fromString($sessionPublicId),
        );
        if ($session === null) {
            return $this->notFound();
        }

        $host = $request->getSchemeAndHttpHost();

        return $this->successResponse([
            'session' => PartySessionResource::from($session),
            'wsUrl'   => sprintf('wss://%s/ws/party/%s', $request->getHost(), $sessionPublicId),
        ]);
    }
}
