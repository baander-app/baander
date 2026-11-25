<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Interface\Request\SubscribeCountryRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(
    name: 'Radio - Country Subscriptions',
    description: 'Subscribe to countries for station sync',
)]
#[Route('/api/radio/subscriptions', name: 'radio_subscription_')]
final class CountrySubscriptionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
        private readonly MessageBusInterface $commandBus,
        private readonly RadioSourcePortInterface $sources,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/radio/subscriptions',
        summary: "List user's country subscriptions",
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of subscriptions',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'sourceId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'countryCode' => new OA\Schema(type: 'string'),
                                        'subscribedAt' => new OA\Schema(type: 'string', format: 'date-time'),
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
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        return $this->successResponse($this->subscriptionPort->listSubscriptions($userId));
    }

    #[OA\Operation\Post(
        path: '/api/radio/subscriptions',
        summary: 'Subscribe to a country',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'sourceId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'countryCode' => new OA\Schema(type: 'string'),
                    ],
                    required: ['sourceId', 'countryCode'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Subscribed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'sourceId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                    'countryCode' => new OA\Schema(type: 'string'),
                                ],
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
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'subscribe', methods: ['POST'])]
    public function subscribe(#[MapRequestPayload] SubscribeCountryRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $sourceId = $payload->sourceId ? $this->sourceId($payload->sourceId) : null;

        $result = $this->subscriptionPort->subscribe($userId, $sourceId, $payload->countryCode);

        return $this->created($result);
    }

    #[OA\Operation\Delete(
        path: '/api/radio/subscriptions/{countryCode}',
        summary: 'Unsubscribe from a country',
        responses: [
            new OA\Response(response: '204', description: 'Unsubscribed'),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{countryCode}', name: 'unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(string $countryCode): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());

        // Find subscription to get sourceId
        $subscriptions = $this->subscriptionPort->listSubscriptions($userId);
        $matching = array_filter($subscriptions, fn (array $s) => $s['countryCode'] === $countryCode);

        if (empty($matching)) {
            return $this->notFound('Not subscribed to this country.');
        }

        $subscription = reset($matching);
        $sourceId = $this->sourceId($subscription['sourceId']);

        $this->subscriptionPort->unsubscribe($userId, $sourceId, $countryCode);

        return $this->noContent();
    }

    #[OA\Operation\Post(
        path: '/api/radio/subscriptions/{countryCode}/refresh',
        summary: 'Manual refresh of country stations',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync triggered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'synced' => new OA\Schema(type: 'string', example: 'queued'),
                                ],
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
            new OA\Response(
                response: '404',
                description: 'Not subscribed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{countryCode}/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(string $countryCode): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $userId = Uuid::fromString($user->getId());
        $subscriptions = $this->subscriptionPort->listSubscriptions($userId);
        $matching = array_filter($subscriptions, fn (array $s) => $s['countryCode'] === $countryCode);

        if (empty($matching)) {
            return $this->notFound('Not subscribed to this country.');
        }

        $subscription = reset($matching);
        $sourceId = $this->sourceId($subscription['sourceId']);

        $this->commandBus->dispatch(new SyncCountryStationsCommand($sourceId, $countryCode));

        return $this->successResponse(['synced' => 'queued']);
    }

    private function getCurrentSecurityUser(): ?AuthenticatedUserIdentityInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $user;
    }
    private function sourceId(string $publicId): Uuid
    {
        try {
            $source = $this->sources->findByPublicId(PublicId::fromString($publicId));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Radio source not found.');
        }

        return ($source ?? throw new NotFoundHttpException('Radio source not found.'))->getId();
    }

}
