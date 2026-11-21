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
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Radio - Stations', description: 'Browse and search radio stations')]
#[Route('/api/radio', name: 'radio_')]
final class RadioStationController
{
    use ApiResponsesTrait;

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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
        parameters: [
            new OA\Parameter(
                name: 'country',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of stations',
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
                                        'externalId' => new OA\Schema(description: 'Station identifier assigned by the external provider', type: 'string'),
                                        'sourceId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'name' => new OA\Schema(type: 'string'),
                                        'countrycode' => new OA\Schema(type: 'string'),
                                        'url_resolved' => new OA\Schema(
                                            type: 'string',
                                            format: 'uri',
                                            nullable: true,
                                        ),
                                        'favicon' => new OA\Schema(
                                            type: 'string',
                                            format: 'uri',
                                            nullable: true,
                                        ),
                                        'tags' => new OA\Schema(type: 'string', nullable: true),
                                        'codec' => new OA\Schema(type: 'string', nullable: true),
                                        'bitrate' => new OA\Schema(type: 'integer', nullable: true),
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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

        $country = $request->query->getString('country') ?: null;
        $query = $request->query->getString('q') ?: null;

        return $this->successResponse($this->stationPort->listStations($country, $query));
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
