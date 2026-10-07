<?php

declare(strict_types=1);

namespace App\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
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

    private const string FROM_DESCRIPTION = 'First day counted (Y-m-d), from its start in the server time zone. Defaults to 30 days before now.';
    private const string TO_DESCRIPTION = 'Last day counted (Y-m-d), up to the start of the next day in the server time zone; must not precede from. Defaults to today.';

    public function __construct(
        private readonly ActivityAnalyticsPortInterface $analytics,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/activity/summary',
        summary: 'Get activity summary statistics',
        parameters: [
            new OA\Parameter(name: 'from', description: self::FROM_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: self::TO_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
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
            new OA\Response(response: '400', description: 'Invalid query parameters', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            new OA\Parameter(name: 'from', description: self::FROM_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: self::TO_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', description: 'Max results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, maximum: 100, minimum: 1)),
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
            new OA\Response(response: '400', description: 'Invalid query parameters', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/top-tracks', name: 'top_tracks', methods: ['GET'])]
    public function topTracks(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $limit = QueryParameters::integer($request->query, 'limit', 10, 1, 100);

        return $this->successResponse($this->analytics->getTopTracks($from, $to, $limit));
    }

    #[OA\Get(
        path: '/api/admin/activity/top-artists',
        summary: 'Get top artists by play count',
        parameters: [
            new OA\Parameter(name: 'from', description: self::FROM_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: self::TO_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', description: 'Max results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, maximum: 100, minimum: 1)),
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
            new OA\Response(response: '400', description: 'Invalid query parameters', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/top-artists', name: 'top_artists', methods: ['GET'])]
    public function topArtists(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $limit = QueryParameters::integer($request->query, 'limit', 10, 1, 100);

        return $this->successResponse($this->analytics->getTopArtists($from, $to, $limit));
    }

    #[OA\Get(
        path: '/api/admin/activity/engagement',
        summary: 'Get user engagement metrics',
        parameters: [
            new OA\Parameter(name: 'from', description: self::FROM_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: self::TO_DESCRIPTION, in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
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
            new OA\Response(response: '400', description: 'Invalid query parameters', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
     * The instants [from, to) covering the requested days. Both dates are inclusive calendar days in
     * the server time zone, so the range ends at the start of the day after `to`. That boundary does
     * not depend on how finely last_played_at is stored, unlike an inclusive end at 23:59:59.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $from = QueryParameters::optionalDate($request->query, 'from') ?? new \DateTimeImmutable('-30 days');
        $lastDay = QueryParameters::optionalDate($request->query, 'to') ?? new \DateTimeImmutable('today');
        $to = $lastDay->modify('+1 day');

        if ($from >= $to) {
            throw new InvalidQueryParameter('to', 'End date must not precede start date.');
        }

        return [$from, $to];
    }
}
