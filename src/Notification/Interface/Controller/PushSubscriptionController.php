<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[OA\Tag(name: 'Push', description: 'Browser push subscription management')]
#[Route('/api/push', name: 'push_')]
final class PushSubscriptionController
{
    use ApiResponsesTrait;

    private const ALLOWED_PUSH_DOMAINS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
        'gcm.googleapis.com',
    ];

    public function __construct(
        private readonly PushSubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    ) {
    }

    /**
     * Subscribe to browser push notifications.
     */
    #[OA\Post(
        path: '/api/push/subscribe',
        summary: 'Subscribe to push notifications',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['endpoint', 'keys', 'contentEncoding'],
                    properties: [
                        new OA\Property(property: 'endpoint', type: 'string'),
                        new OA\Property(property: 'keys', properties: [
                            new OA\Property(property: 'p256dh', type: 'string'),
                            new OA\Property(property: 'auth', type: 'string'),
                        ], type: 'object'),
                        new OA\Property(property: 'contentEncoding', type: 'string', enum: ['aesgcm', 'aes128gcm']),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Subscribed', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])),
            new OA\Response(response: '422', description: 'Invalid subscription', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribe(Request $request): JsonResponse
    {
        $data = $this->jsonEncoder->decode((string)$request->getContent(), 'json');

        $errors = $this->validateSubscription($data);
        if ($errors !== []) {
            return $this->errorResponse('Invalid subscription data.', 422, $errors);
        }

        $endpoint = $data['endpoint'];
        $parsedUrl = parse_url($endpoint);

        if ($parsedUrl === false || ($parsedUrl['scheme'] ?? '') !== 'https') {
            return $this->errorResponse('Endpoint must use HTTPS.', 422);
        }

        $host = $parsedUrl['host'] ?? '';
        if (!$this->isAllowedPushDomain($host)) {
            return $this->errorResponse('Endpoint domain is not a known push service.', 422);
        }

        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = \App\Shared\Domain\Model\Uuid::fromString($user->getId());

        $existing = $this->subscriptionRepository->findByEndpoint($endpoint);
        if ($existing !== null) {
            return $this->successResponse(['status' => 'already_subscribed']);
        }

        $subscription = new PushSubscriptionEntity(
            userId: $userId,
            endpoint: $endpoint,
            publicKey: $data['keys']['p256dh'],
            authKey: $data['keys']['auth'],
            contentEncoding: $data['contentEncoding'],
            userAgent: $request->headers->get('User-Agent'),
        );

        $this->subscriptionRepository->save($subscription);

        return $this->created(['status' => 'subscribed']);
    }

    /**
     * Unsubscribe from push notifications by endpoint.
     */
    #[OA\Delete(
        path: '/api/push/subscribe',
        summary: 'Unsubscribe from push notifications',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['endpoint'],
                    properties: [
                        new OA\Property(property: 'endpoint', type: 'string'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '204', description: 'Unsubscribed'),
        ],
    )]
    #[Route('/subscribe', name: 'unsubscribe', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $this->jsonEncoder->decode((string)$request->getContent(), 'json');

        if (!isset($data['endpoint']) || !is_string($data['endpoint'])) {
            return $this->errorResponse('Endpoint is required.', 422);
        }

        $this->subscriptionRepository->removeByEndpoint($data['endpoint']);

        return $this->noContent();
    }

    /**
     * Remove all push subscriptions for the authenticated user.
     */
    #[OA\Delete(
        path: '/api/push/subscriptions',
        summary: 'Remove all push subscriptions',
        responses: [
            new OA\Response(response: '204', description: 'All subscriptions removed'),
        ],
    )]
    #[Route('/subscriptions', name: 'remove_all', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removeAll(Request $request): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = \App\Shared\Domain\Model\Uuid::fromString($user->getId());

        $this->subscriptionRepository->removeAllForUser($userId);

        return $this->noContent();
    }

    /**
     * @return array<string, string>
     */
    private function validateSubscription(array $data): array
    {
        $constraints = new Assert\Collection([
            'endpoint'        => [new Assert\NotBlank(), new Assert\Url()],
            'keys'            => new Assert\Collection([
                'p256dh' => [new Assert\NotBlank()],
                'auth'   => [new Assert\NotBlank()],
            ]),
            'contentEncoding' => [new Assert\NotBlank(), new Assert\Choice(choices: ['aesgcm', 'aes128gcm'])],
        ]);

        $violations = $this->validator->validate($data, $constraints);

        $errors = [];
        foreach ($violations as $violation) {
            $field = str_replace(['[', ']'], '', $violation->getPropertyPath());
            $errors[$field] = $violation->getMessage();
        }

        return $errors;
    }

    private function isAllowedPushDomain(string $host): bool
    {
        foreach (self::ALLOWED_PUSH_DOMAINS as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}
