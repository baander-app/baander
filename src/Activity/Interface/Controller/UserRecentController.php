<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Resource\RecentItemResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'User', description: 'User-specific data endpoints')]
#[Route('/api/user', name: 'user_')]
final class UserRecentController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly ActivityPortInterface $activityService,
        private readonly ActivityEnrichmentService $enrichmentService,
    ) {
    }

    /**
     * Get recently played items for the authenticated user.
     */
    #[OA\Get(
        path: '/api/user/recent',
        summary: 'Get recently played items',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Max items to return (1-20)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, minimum: 1, maximum: 20)),
            new OA\Parameter(name: 'mediaType', description: 'Filter by media type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: RecentItemResource::class)))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/recent', name: 'recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $limit = min(20, max(1, (int) $request->query->get('limit', 5)));
        $mediaType = $request->query->getAlnum('mediaType');

        // Fetch more than needed if filtering, to ensure enough results after filtering
        $fetchLimit = $mediaType !== '' ? 100 : $limit;
        $activities = $this->activityService->getRecentlyPlayed(
            Uuid::fromString($user->getId()),
            $fetchLimit,
        );

        if ($mediaType !== '') {
            $activities = $this->filterByMediaType($activities, $mediaType);
            $activities = array_slice($activities, 0, $limit);
        }

        $baseUrl = $request->getSchemeAndHttpHost();
        $enriched = $this->enrichmentService->enrich($activities, $baseUrl);

        $mapped = array_map(
            fn(array $item) => RecentItemResource::from($item),
            $enriched,
        );

        return $this->successResponse($mapped);
    }

    /**
     * @param MediaActivity[] $activities
     * @return MediaActivity[]
     */
    private function filterByMediaType(array $activities, string $mediaType): array
    {
        return array_values(array_filter($activities, function (MediaActivity $activity) use ($mediaType): bool {
            return match ($mediaType) {
                'music' => ($activity->getSongId() !== null || $activity->getAlbumId() !== null) && $activity->getMovieId() === null,
                'movies' => $activity->getMovieId() !== null,
                default => false, // tv, podcasts, concerts, ebooks not tracked yet
            };
        }));
    }
}
