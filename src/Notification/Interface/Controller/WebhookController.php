<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Notification\Application\DTO\CreateWebhookCommand;
use App\Notification\Application\DTO\DeleteWebhookCommand;
use App\Notification\Application\DTO\ListWebhooksQuery;
use App\Notification\Application\DTO\RotateWebhookSecretCommand;
use App\Notification\Application\DTO\UpdateWebhookCommand;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[OA\Tag(name: 'Webhooks', description: 'Outgoing webhook management (admin only)')]
#[Route('/api/webhooks', name: 'webhook_')]
#[IsGranted('ROLE_ADMIN')]
final class WebhookController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * List all configured webhooks.
     */
    #[OA\Get(
        path: '/api/webhooks/',
        summary: 'List all webhooks',
        responses: [
            new OA\Response(response: '200', description: 'List of webhooks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'url', type: 'string')]))])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[CliCounterpart('app:webhook:list')]
    public function index(): JsonResponse
    {
        $webhooks = $this->dispatch(new ListWebhooksQuery());

        return $this->successResponse(WebhookResource::collection($webhooks));
    }

    /**
     * Create a new webhook.
     */
    #[OA\Post(
        path: '/api/webhooks/',
        summary: 'Create a webhook',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['url'],
                    properties: [
                        new OA\Property(property: 'url', type: 'string', format: 'uri'),
                        new OA\Property(property: 'category_filter', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Webhook created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'secret', type: 'string')])])),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'create', methods: ['POST'])]
    #[CliCounterpart('app:webhook:create')]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $issued = $this->dispatch(new CreateWebhookCommand($data['url'] ?? null, $data['category_filter'] ?? null));

        return $this->successResponse(WebhookResource::created($issued), Response::HTTP_CREATED);
    }

    /**
     * Update a webhook's URL and/or category filter.
     */
    #[OA\Put(
        path: '/api/webhooks/{id}',
        summary: 'Update a webhook',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'url', type: 'string', format: 'uri'),
                        new OA\Property(property: 'category_filter', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'id', description: 'Webhook UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Webhook updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'url', type: 'string')])])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[CliCounterpart('app:webhook:update')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $webhook = $this->dispatch(new UpdateWebhookCommand(
            webhookId: $id,
            changesUrl: array_key_exists('url', $data),
            url: $data['url'] ?? null,
            changesCategoryFilter: array_key_exists('category_filter', $data),
            categoryFilter: $data['category_filter'] ?? null,
        ));

        return $this->successResponse(WebhookResource::from($webhook));
    }

    /**
     * Delete a webhook.
     */
    #[OA\Delete(
        path: '/api/webhooks/{id}',
        summary: 'Delete a webhook',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Webhook UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'The ID is not a UUID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[CliCounterpart('app:webhook:delete')]
    public function delete(string $id): JsonResponse
    {
        $this->dispatch(new DeleteWebhookCommand($id));

        return $this->noContent();
    }

    #[OA\Post(
        path: '/api/webhooks/{id}/rotate-secret',
        summary: 'Rotate a webhook secret; the webhook keeps its signature version',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: '200', description: 'New secret, returned once', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', type: 'object', required: ['id', 'secret', 'signing_version'], properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'secret', type: 'string', minLength: 64, maxLength: 64, pattern: '^[a-f0-9]{64}$'),
                    new OA\Property(property: 'signing_version', type: 'integer', enum: [2]),
                ])],
            )),
            new OA\Response(response: '403', description: 'Administrator required', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Webhook not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'The ID is not a UUID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{id}/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    #[CliCounterpart('app:webhook:rotate-secret')]
    public function rotateSecret(string $id): JsonResponse
    {
        $issued = $this->dispatch(new RotateWebhookSecretCommand($id));

        return $this->successResponse(WebhookResource::rotated($issued));
    }
}
