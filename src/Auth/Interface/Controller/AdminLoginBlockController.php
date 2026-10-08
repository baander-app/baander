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
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/admin/login-blocks',
        summary: 'List recent honeypot blocks (paginated)',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Results per page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'offset', description: 'Result offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
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
            new OA\Response(response: '400', description: 'Invalid pagination', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
    #[CliCounterpart('app:login-block:delete')]
    public function delete(string $id): JsonResponse
    {
        $this->dispatch(new DeleteLoginBlockCommand($id));

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
