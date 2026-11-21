<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Activity\Interface\Request\ActivityAnalyticsRange;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
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

    private const string FROM_DESCRIPTION = 'First day counted (Y-m-d), from its start in the server time zone. Defaults to 30 days before now.';
    private const string TO_DESCRIPTION = 'Last day counted (Y-m-d), up to the start of the next day in the server time zone; must not precede from. Defaults to today.';

    public function __construct(
        private readonly ActivityAnalyticsPortInterface $analytics,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/activity/summary',
        summary: 'Get activity summary statistics',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: self::FROM_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: self::TO_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Activity summary',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'total_plays' => new OA\Schema(type: 'integer'),
                                    'unique_tracks' => new OA\Schema(type: 'integer'),
                                    'unique_artists' => new OA\Schema(type: 'integer'),
                                    'total_listening_time' => new OA\Schema(type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid query parameters',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    #[CliCounterpart('app:activity:summary')]
    public function summary(Request $request): JsonResponse
    {
        $range = ActivityAnalyticsRange::fromQuery($request->query);

        return $this->successResponse($this->analytics->getSummary($range->from, $range->to));
    }

    #[OA\Operation\Get(
        path: '/api/admin/activity/top-tracks',
        summary: 'Get top tracks by play count',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: self::FROM_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: self::TO_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Max results',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 10,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Top tracks list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    properties: [
                                        'track_name' => new OA\Schema(type: 'string'),
                                        'artist_name' => new OA\Schema(type: 'string', nullable: true),
                                        'album_name' => new OA\Schema(type: 'string', nullable: true),
                                        'play_count' => new OA\Schema(type: 'integer'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid query parameters',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/top-tracks', name: 'top_tracks', methods: ['GET'])]
    #[CliCounterpart('app:activity:top-tracks')]
    public function topTracks(Request $request): JsonResponse
    {
        $range = ActivityAnalyticsRange::fromQuery($request->query);
        $limit = ActivityAnalyticsRange::limit($request->query);

        return $this->successResponse($this->analytics->getTopTracks($range->from, $range->to, $limit));
    }

    #[OA\Operation\Get(
        path: '/api/admin/activity/top-artists',
        summary: 'Get top artists by play count',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: self::FROM_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: self::TO_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Max results',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 10,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Top artists list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    properties: [
                                        'artist_name' => new OA\Schema(type: 'string'),
                                        'play_count' => new OA\Schema(type: 'integer'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid query parameters',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/top-artists', name: 'top_artists', methods: ['GET'])]
    #[CliCounterpart('app:activity:top-artists')]
    public function topArtists(Request $request): JsonResponse
    {
        $range = ActivityAnalyticsRange::fromQuery($request->query);
        $limit = ActivityAnalyticsRange::limit($request->query);

        return $this->successResponse($this->analytics->getTopArtists($range->from, $range->to, $limit));
    }

    #[OA\Operation\Get(
        path: '/api/admin/activity/engagement',
        summary: 'Get user engagement metrics',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: self::FROM_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: self::TO_DESCRIPTION,
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Engagement metrics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(
                                properties: [
                                    'active_users' => new OA\Schema(type: 'integer'),
                                    'avg_plays_per_user' => new OA\Schema(type: 'number'),
                                    'avg_session_length' => new OA\Schema(type: 'number'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid query parameters',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/engagement', name: 'engagement', methods: ['GET'])]
    #[CliCounterpart('app:activity:engagement')]
    public function engagement(Request $request): JsonResponse
    {
        $range = ActivityAnalyticsRange::fromQuery($request->query);

        return $this->successResponse($this->analytics->getEngagement($range->from, $range->to));
    }
}
