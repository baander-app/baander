<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Exception\WebSocketOriginRejectedException;
use App\Auth\Application\Exception\WebSocketTicketRejectedException;
use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Port\WebSocketTicketIssuerInterface;
use App\Auth\Interface\Resource\WebSocketTicketResource;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
final readonly class WebSocketTicketController
{
    use ApiResponsesTrait;

    public function __construct(private Security $security, private WebSocketTicketIssuerInterface $tickets) {}

    #[OA\Post(path: '/api/ws/tickets', summary: 'Issue a single-use WebSocket handshake ticket', parameters: [
        new OA\Parameter(name: 'Origin', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: '200', description: 'A ticket valid for at most thirty seconds', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', ref: new Model(type: WebSocketTicketResource::class)),
        ])),
        new OA\Response(response: '401', description: 'Authentication required', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '403', description: 'DPoP proof or Origin refused', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '503', description: 'Ticket authorization or storage unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
    ])]
    #[Route('/api/ws/tickets', name: 'auth_websocket_ticket', methods: ['POST'])]
    public function issue(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        $tokenId = $request->attributes->get('oauth_access_token_id');
        if (!$user instanceof AuthenticatedUserIdentityInterface || !is_string($tokenId) || $tokenId === '') {
            $response = $this->unauthorized();
        } else {
            try {
                $ticket = $this->tickets->issue($user->getId(), $tokenId, $request->headers->get('Origin', ''));
                $response = $this->successResponse(WebSocketTicketResource::from($ticket));
            } catch (WebSocketOriginRejectedException) {
                $response = $this->forbidden('WebSocket Origin is not allowed.');
            } catch (WebSocketTicketRejectedException) {
                $response = $this->unauthorized();
            } catch (WebSocketTicketUnavailableException) {
                $response = $this->errorResponse('WebSocket authentication is temporarily unavailable.', 503);
                $response->headers->set('Retry-After', '1');
            }
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
