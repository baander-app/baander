<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Application\LiveUpdates\LiveAccessDenied;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Resource\LiveRecoveryResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[OA\Tag(name: 'Live Updates', description: 'Authenticated live update recovery endpoints')]
final readonly class LiveRecoveryController
{
    public function __construct(private LiveSnapshotReader $snapshots, private Security $security) {}

    #[Route('/api/live/recovery', name: 'live_recovery', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[OA\Get(
        path: '/api/live/recovery',
        summary: 'Recover current live update authorization and health',
        responses: [
            new OA\Response(response: 200, description: 'Current authorized live families', content: new OA\JsonContent(ref: new Model(type: LiveRecoveryResource::class))),
            new OA\Response(response: 401, description: 'Authentication required'),
            new OA\Response(response: 403, description: 'Current principal unavailable'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            throw new AccessDeniedException('Authentication required.');
        }
        try {
            $snapshot = $this->snapshots->read(Uuid::fromString($user->getId()));
        } catch (LiveAccessDenied $error) {
            throw new AccessDeniedException('Current principal unavailable.', $error);
        }

        return new JsonResponse(LiveRecoveryResource::from($snapshot), headers: ['Cache-Control' => 'private, no-store']);
    }
}
