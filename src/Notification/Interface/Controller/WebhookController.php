<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Application\Port\WebhookDestinationPortInterface;
use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[OA\Tag(name: 'Webhooks', description: 'Outgoing webhook management (admin only)')]
#[Route('/api/webhooks', name: 'webhook_')]
#[IsGranted('ROLE_ADMIN')]
final class WebhookController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HmacSigner $hmacSigner,
        private readonly WebhookDestinationPortInterface $destinations,
        private readonly WebhookSecretPortInterface $secrets,
    )
    {
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
    public function index(): JsonResponse
    {
        $webhooks = $this->entityManager
            ->getRepository(WebhookEntity::class)
            ->findAll();

        $data = array_map(static fn(WebhookEntity $w) => [
            'id'              => $w->getId()->toString(),
            'url'             => $w->getUrl(),
            'category_filter' => $w->getCategoryFilter(),
            'created_at'      => $w->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'      => $w->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $webhooks);

        return $this->successResponse($data);
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
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $url = $data['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            return $this->errorResponse('URL is required.', 422);
        }

        if ($this->destinations->resolve($url) === null) {
            return $this->errorResponse('Webhook destination is not allowed or cannot be resolved.', 422);
        }

        $categoryFilter = $data['category_filter'] ?? null;
        if (!$this->validCategoryFilter($categoryFilter)) {
            return $this->errorResponse('category_filter must contain supported notification categories.', 422);
        }

        $secret = bin2hex(random_bytes(32));
        $secretHash = $this->hmacSigner->hashSecret($secret);

        $webhook = new WebhookEntity(Uuid::generate());
        $webhook->setUrl($url);
        $webhook->setCategoryFilter($categoryFilter);
        $webhook->setEncryptedSigningSecret($this->secrets->encrypt($secret), $secretHash);

        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        return $this->created([
            'id'              => $webhook->getId()->toString(),
            'url'             => $webhook->getUrl(),
            'category_filter' => $webhook->getCategoryFilter(),
            'secret'          => $secret,
            'signing_version' => $webhook->getSigningVersion(),
            'created_at'      => $webhook->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'      => $webhook->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
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
    public function update(string $id, Request $request): JsonResponse
    {
        $webhook = $this->findWebhook($id);
        if ($webhook === null) {
            return $this->notFound('Webhook not found.');
        }

        $data = $request->toArray();

        if (array_key_exists('category_filter', $data) && !$this->validCategoryFilter($data['category_filter'])) {
            return $this->errorResponse('category_filter must contain supported notification categories.', 422);
        }

        $url = $data['url'] ?? null;
        if (array_key_exists('url', $data)) {
            if (!is_string($url) || trim($url) === '') {
                return $this->errorResponse('URL must be a non-empty string.', 422);
            }

            if ($this->destinations->resolve($url) === null) {
                return $this->errorResponse('Webhook destination is not allowed or cannot be resolved.', 422);
            }

            $webhook->setUrl($url);
        }

        if (array_key_exists('category_filter', $data)) {
            $categoryFilter = $data['category_filter'];
            $webhook->setCategoryFilter($categoryFilter);
        }

        $webhook->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->successResponse([
            'id'              => $webhook->getId()->toString(),
            'url'             => $webhook->getUrl(),
            'category_filter' => $webhook->getCategoryFilter(),
            'created_at'      => $webhook->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'      => $webhook->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
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
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $webhook = $this->findWebhook($id);
        if ($webhook === null) {
            return $this->notFound('Webhook not found.');
        }

        $this->entityManager->remove($webhook);
        $this->entityManager->flush();

        return $this->noContent();
    }

    #[OA\Post(
        path: '/api/webhooks/{id}/rotate-secret',
        summary: 'Rotate a webhook secret and upgrade to signature version 2',
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
        ],
    )]
    #[Route('/{id}/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    public function rotateSecret(string $id): JsonResponse
    {
        $webhook = $this->findWebhook($id);
        if ($webhook === null) {
            return $this->notFound('Webhook not found.');
        }
        $secret = bin2hex(random_bytes(32));
        $webhook->setEncryptedSigningSecret($this->secrets->encrypt($secret), $this->hmacSigner->hashSecret($secret));
        $this->entityManager->flush();

        return $this->successResponse(['id' => $id, 'secret' => $secret, 'signing_version' => 2]);
    }

    private function validCategoryFilter(mixed $filter): bool
    {
        if ($filter === null) {
            return true;
        }
        if (!is_array($filter) || !array_is_list($filter)) {
            return false;
        }
        foreach ($filter as $category) {
            if (!is_string($category) || NotificationCategory::tryFrom($category) === null) {
                return false;
            }
        }
        return true;
    }

    private function findWebhook(string $id): ?WebhookEntity
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\Throwable) {
            return null;
        }

        return $this->entityManager
            ->getRepository(WebhookEntity::class)
            ->find($uuid);
    }
}
