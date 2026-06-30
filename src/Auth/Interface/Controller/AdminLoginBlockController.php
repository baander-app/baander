<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Interface\Resource\LoginBlockResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Admin / Login Blocks', description: 'Honeypot login block management')]
#[Route('/api/admin/login-blocks', name: 'admin_login_blocks_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLoginBlockController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly LoginBlockRepositoryInterface $repository,
    ) {}

    #[OA\Get(
        path: '/api/admin/login-blocks',
        summary: 'List recent honeypot blocks (paginated)',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Results per page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'offset', description: 'Result offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated block list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: LoginBlockResource::class))),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'limit', type: 'integer'),
                            new OA\Property(property: 'offset', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 50);
        $offset = (int) $request->query->get('offset', 0);

        $blocks = $this->repository->findRecent($limit, $offset);
        $total = $this->repository->countRecent();

        return new JsonResponse([
            'data' => LoginBlockResource::collection($blocks),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/admin/login-blocks/{id}',
        summary: 'Delete a single block',
        responses: [
            new OA\Response(response: '204', description: 'Block deleted'),
            new OA\Response(response: '404', description: 'Block not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(string $id): JsonResponse
    {
        $this->repository->deleteByUuid(Uuid::fromString($id));

        return $this->noContent();
    }

    #[OA\Delete(
        path: '/api/admin/login-blocks',
        summary: 'Delete all blocks',
        responses: [
            new OA\Response(response: '204', description: 'All blocks deleted'),
        ],
    )]
    #[Route('', name: 'delete_all', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteAll(): JsonResponse
    {
        $this->repository->deleteAll();

        return $this->noContent();
    }
}
