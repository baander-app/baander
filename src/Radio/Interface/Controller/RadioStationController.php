<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/radio/countries',
        summary: 'List available countries from IPRD',
        responses: [
            new OA\Response(response: '200', description: 'List of countries', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'code', type: 'string', description: 'ISO 3166-1 alpha-2 country code'),
                    new OA\Property(property: 'name', type: 'string'),
                ], type: 'object'))],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/countries', name: 'countries', methods: ['GET'])]
    public function countries(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        return $this->successResponse($this->subscriptionPort->listAvailableCountries());
    }

    #[OA\Get(
        path: '/api/radio/stations',
        summary: 'Browse/search stations',
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'List of stations', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'stationuuid', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'countrycode', type: 'string'),
                    new OA\Property(property: 'url_resolved', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'favicon', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'tags', type: 'string', nullable: true),
                    new OA\Property(property: 'codec', type: 'string', nullable: true),
                    new OA\Property(property: 'bitrate', type: 'integer', nullable: true),
                ], type: 'object'))],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/stations', name: 'stations', methods: ['GET'])]
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

    private function getCurrentSecurityUser(): ?SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            return null;
        }

        return $user;
    }
}
