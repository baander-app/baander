<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/activity', name: 'admin_activity_')]
final class ActivityAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly ActivityAnalyticsPortInterface $analytics,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/activity/summary',
        summary: 'Get activity summary statistics',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: 'End date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Activity summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'total_plays', type: 'integer'),
                            new OA\Property(property: 'unique_tracks', type: 'integer'),
                            new OA\Property(property: 'unique_artists', type: 'integer'),
                            new OA\Property(property: 'total_listening_time', type: 'integer'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);

        return $this->successResponse($this->analytics->getSummary($from, $to));
    }

    #[OA\Get(
        path: '/api/admin/activity/top-tracks',
        summary: 'Get top tracks by play count',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: 'End date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', description: 'Max results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Top tracks list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'track_name', type: 'string'),
                                new OA\Property(property: 'artist_name', type: 'string', nullable: true),
                                new OA\Property(property: 'album_name', type: 'string', nullable: true),
                                new OA\Property(property: 'play_count', type: 'integer'),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/top-tracks', name: 'top_tracks', methods: ['GET'])]
    public function topTracks(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));

        return $this->successResponse($this->analytics->getTopTracks($from, $to, $limit));
    }

    #[OA\Get(
        path: '/api/admin/activity/top-artists',
        summary: 'Get top artists by play count',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: 'End date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', description: 'Max results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Top artists list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'artist_name', type: 'string'),
                                new OA\Property(property: 'play_count', type: 'integer'),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/top-artists', name: 'top_artists', methods: ['GET'])]
    public function topArtists(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));

        return $this->successResponse($this->analytics->getTopArtists($from, $to, $limit));
    }

    #[OA\Get(
        path: '/api/admin/activity/engagement',
        summary: 'Get user engagement metrics',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: 'End date (Y-m-d)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Engagement metrics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'active_users', type: 'integer'),
                            new OA\Property(property: 'avg_plays_per_user', type: 'number'),
                            new OA\Property(property: 'avg_session_length', type: 'number'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/engagement', name: 'engagement', methods: ['GET'])]
    public function engagement(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);

        return $this->successResponse($this->analytics->getEngagement($from, $to));
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $fromString = $request->query->get('from');
        $toString = $request->query->get('to');

        $from = $fromString !== null
            ? new \DateTimeImmutable($fromString)
            : new \DateTimeImmutable('-30 days');

        $to = $toString !== null
            ? new \DateTimeImmutable($toString . ' 23:59:59')
            : new \DateTimeImmutable('today 23:59:59');

        return [$from, $to];
    }
}
