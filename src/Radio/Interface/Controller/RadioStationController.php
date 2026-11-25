<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Radio - Stations', description: 'Browse and search radio stations')]
#[Route('/api/radio', name: 'radio_')]
final class RadioStationController
{
    use ApiResponsesTrait;

    /** The most stations one request names by public ID; it keeps the query string short. */
    private const int MAX_PUBLIC_IDS = 100;

    public function __construct(
        private readonly Security $security,
        private readonly RadioStationPortInterface $stationPort,
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/radio/countries',
        summary: 'List available countries from IPRD',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of countries',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'code' => new OA\Schema(description: 'ISO 3166-1 alpha-2 country code', type: 'string'),
                                        'name' => new OA\Schema(type: 'string'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/countries', name: 'countries', methods: ['GET'])]
    #[CliCounterpart('app:radio:country:list')]
    public function countries(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        return $this->successResponse($this->subscriptionPort->listAvailableCountries());
    }

    #[OA\Operation\Get(
        path: '/api/radio/stations',
        summary: 'Browse/search stations',
        description: 'A page of the synced stations that match every given filter, ordered by name and then by id.',
        parameters: [
            new OA\Parameter(
                name: 'country',
                in: 'query',
                description: 'Only stations of this country code',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Only stations whose name contains this text, case-insensitively; % and _ match themselves',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'publicIds',
                in: 'query',
                description: 'Only these stations: comma-separated station public IDs, at most ' . self::MAX_PUBLIC_IDS,
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of stations to return',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: RadioStationPortInterface::MAX_LIMIT,
                    default: RadioStationPortInterface::DEFAULT_LIMIT,
                ),
            ),
            new OA\Parameter(
                name: 'offset',
                in: 'query',
                description: 'Number of stations to skip',
                required: false,
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
                description: 'A page of stations',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'publicId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'sourceId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'externalId' => new OA\Schema(description: 'Station identifier assigned by the external provider', type: 'string'),
                                        'name' => new OA\Schema(type: 'string'),
                                        'country' => new OA\Schema(type: 'string'),
                                        'language' => new OA\Schema(type: 'string', nullable: true),
                                        'genres' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
                                        'tags' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
                                        'streams' => new OA\Schema(
                                            type: 'array',
                                            items: new OA\Schema(
                                                type: 'object',
                                                properties: [
                                                    'url' => new OA\Schema(type: 'string'),
                                                    'format' => new OA\Schema(type: 'string'),
                                                    'bitrate' => new OA\Schema(type: 'integer'),
                                                    'reliability' => new OA\Schema(type: 'number'),
                                                ],
                                                required: ['url', 'format', 'bitrate', 'reliability'],
                                            ),
                                        ),
                                        'logo' => new OA\Schema(type: 'string', nullable: true),
                                        'website' => new OA\Schema(type: 'string', nullable: true),
                                        'lastCheckedAt' => new OA\Schema(type: 'string', format: 'date-time', nullable: true),
                                        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                        'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    ],
                                    required: ['publicId', 'sourceId', 'externalId', 'name', 'country', 'language', 'genres', 'tags', 'streams', 'logo', 'website', 'lastCheckedAt', 'createdAt', 'updatedAt'],
                                ),
                            ),
                            'meta' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'total' => new OA\Schema(description: 'Stations that match the filters', type: 'integer'),
                                    'limit' => new OA\Schema(type: 'integer'),
                                    'offset' => new OA\Schema(type: 'integer'),
                                ],
                                required: ['total', 'limit', 'offset'],
                            ),
                        ],
                        required: ['data', 'meta'],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid limit, offset or public IDs',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/stations', name: 'stations', methods: ['GET'])]
    #[CliCounterpart('app:radio:station:list')]
    public function stations(Request $request): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $pagination = QueryParameters::pagination($request->query, RadioStationPortInterface::DEFAULT_LIMIT, RadioStationPortInterface::MAX_LIMIT);
        $page = $this->stationPort->listStations(
            $request->query->getString('country') ?: null,
            $request->query->getString('q') ?: null,
            self::publicIds($request->query),
            $pagination->limit,
            $pagination->offset,
        );

        return $this->json([
            'data' => $page->stations,
            'meta' => [
                'total' => $page->total,
                'limit' => $pagination->limit,
                'offset' => $pagination->offset,
            ],
        ]);
    }

    /**
     * @param InputBag<covariant string|int|float|bool|null> $query
     *
     * @return list<PublicId>
     */
    private static function publicIds(InputBag $query): array
    {
        $value = $query->all()['publicIds'] ?? '';
        if (!is_string($value)) {
            throw new InvalidQueryParameter('publicIds', 'publicIds must be comma-separated public IDs.');
        }
        if ($value === '') {
            return [];
        }

        $parts = explode(',', $value);
        if (count($parts) > self::MAX_PUBLIC_IDS) {
            throw new InvalidQueryParameter('publicIds', sprintf('publicIds names at most %d stations.', self::MAX_PUBLIC_IDS));
        }

        try {
            return array_map(PublicId::fromString(...), $parts);
        } catch (\InvalidArgumentException) {
            throw new InvalidQueryParameter('publicIds', 'publicIds must be comma-separated public IDs.');
        }
    }

    private function getCurrentSecurityUser(): ?AuthenticatedUserIdentityInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $user;
    }
}
