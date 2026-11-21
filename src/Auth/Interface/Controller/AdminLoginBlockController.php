<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Command\LoginBlock\DeleteAllLoginBlocksCommand;
use App\Auth\Application\Command\LoginBlock\DeleteLoginBlockCommand;
use App\Auth\Application\DTO\LoginBlockPage;
use App\Auth\Application\Query\LoginBlock\ListLoginBlocksQuery;
use App\Auth\Interface\Resource\LoginBlockResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The login honeypot's blocks for administrators: administrators list them and super
 * administrators remove them. The app:login-block:* console commands dispatch the same
 * application messages.
 */
#[OA\Tag(name: 'Admin / Login Blocks', description: 'Honeypot login block management')]
#[Route('/api/admin/login-blocks', name: 'admin_login_blocks_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLoginBlockController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    #[OA\Operation\Get(
        path: '/api/admin/login-blocks',
        summary: 'List recent honeypot blocks (paginated)',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Results per page',
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 50,
                ),
            ),
            new OA\Parameter(
                name: 'offset',
                in: 'query',
                description: 'Result offset',
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                    default: 0,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated block list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: LoginBlockResource::class),
                            ),
                            'meta' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'total' => new OA\Schema(type: 'integer'),
                                    'limit' => new OA\Schema(type: 'integer'),
                                    'offset' => new OA\Schema(type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid pagination',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    #[CliCounterpart('app:login-block:list')]
    public function list(Request $request): JsonResponse
    {
        $pagination = QueryParameters::pagination($request->query, ListLoginBlocksQuery::DEFAULT_LIMIT, ListLoginBlocksQuery::MAX_LIMIT);

        $page = $this->dispatch(new ListLoginBlocksQuery($pagination->limit, $pagination->offset));
        assert($page instanceof LoginBlockPage);

        return new JsonResponse([
            'data' => LoginBlockResource::collection($page->blocks),
            'meta' => [
                'total' => $page->total,
                'limit' => $pagination->limit,
                'offset' => $pagination->offset,
            ],
        ]);
    }

    #[OA\Operation\Delete(
        path: '/api/admin/login-blocks/{publicId}',
        summary: 'Delete a single block',
        responses: [
            new OA\Response(response: '204', description: 'Block deleted'),
            new OA\Response(
                response: '404',
                description: 'Block not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:login-block:delete')]
    public function delete(string $publicId): JsonResponse
    {
        $this->dispatch(new DeleteLoginBlockCommand($publicId));

        return $this->noContent();
    }

    #[OA\Operation\Delete(
        path: '/api/admin/login-blocks',
        summary: 'Delete all blocks',
        responses: [
            new OA\Response(response: '204', description: 'All blocks deleted'),
        ],
    )]
    #[Route('', name: 'delete_all', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:login-block:delete')]
    public function deleteAll(): JsonResponse
    {
        $this->dispatch(new DeleteAllLoginBlocksCommand());

        return $this->noContent();
    }

    /** Dispatches synchronously; a handler's not-found or invalid-input outcome reaches the exception subscriber. */
    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
