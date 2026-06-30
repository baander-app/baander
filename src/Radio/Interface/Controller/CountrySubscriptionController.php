<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Interface\Request\SubscribeCountryRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Radio - Country Subscriptions', description: 'Subscribe to countries for station sync')]
#[Route('/api/radio/subscriptions', name: 'radio_subscription_')]
final class CountrySubscriptionController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Get(
        path: '/api/radio/subscriptions',
        summary: "List user's country subscriptions",
        responses: [
            new OA\Response(response: '200', description: 'List of subscriptions', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'sourceId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'countryCode', type: 'string'),
                    new OA\Property(property: 'subscribedAt', type: 'string', format: 'date-time'),
                ], type: 'object'))],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Post(
        path: '/api/radio/subscriptions',
        summary: 'Subscribe to a country',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sourceId', 'countryCode'],
                properties: [
                    new OA\Property(property: 'sourceId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'countryCode', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Subscribed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'sourceId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'countryCode', type: 'string'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
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
        $sourceId = $payload->sourceId ? Uuid::fromString($payload->sourceId) : null;

        $result = $this->subscriptionPort->subscribe($userId, $sourceId, $payload->countryCode);

        return $this->created($result);
    }

    #[OA\Delete(
        path: '/api/radio/subscriptions/{countryCode}',
        summary: 'Unsubscribe from a country',
        responses: [
            new OA\Response(response: '204', description: 'Unsubscribed'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
        $sourceId = Uuid::fromString($subscription['sourceId']);

        $this->subscriptionPort->unsubscribe($userId, $sourceId, $countryCode);

        return $this->noContent();
    }

    #[OA\Post(
        path: '/api/radio/subscriptions/{countryCode}/refresh',
        summary: 'Manual refresh of country stations',
        responses: [
            new OA\Response(response: '200', description: 'Sync triggered', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'synced', type: 'string', example: 'queued'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not subscribed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
        $sourceId = Uuid::fromString($subscription['sourceId']);

        $this->commandBus->dispatch(new SyncCountryStationsCommand($sourceId, $countryCode));

        return $this->successResponse(['synced' => 'queued']);
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
