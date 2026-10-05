<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Notification\Application\Port\PushSubscriptionRegistrationPortInterface;
use App\Notification\Application\Port\PushSubscriptionRemovalPortInterface;
use App\Shared\Domain\Model\Uuid;
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

    /** @param list<string> $allowedPushDomains */
    public function __construct(
        private readonly PushSubscriptionRegistrationPortInterface $subscriptionRegistration,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
        private readonly PushSubscriptionRemovalPortInterface $subscriptionRemoval,
        private readonly array $allowedPushDomains,
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
            new OA\Response(response: '200', description: 'Updated owned subscription', content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['subscribed'])],
            )),
            new OA\Response(response: '201', description: 'Subscribed', content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['subscribed'])],
            )),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '409', description: 'Subscription unavailable', content: new OA\JsonContent(
                ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class),
            )),
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

        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $registration = new PushSubscriptionRegistration(
            endpoint: $endpoint,
            publicKey: $data['keys']['p256dh'],
            authKey: $data['keys']['auth'],
            contentEncoding: $data['contentEncoding'],
            userAgent: $request->headers->get('User-Agent'),
        );
        $result = $this->subscriptionRegistration->registerForUser(Uuid::fromString($user->getId()), $registration);
        if ($result === PushSubscriptionRegistrationResult::Conflict) {
            return $this->errorResponse('Subscription could not be registered.', 409);
        }

        return $this->json(['status' => 'subscribed'], $result === PushSubscriptionRegistrationResult::Created ? 201 : 200);
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
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '422', description: 'Invalid endpoint'),
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

        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->errorResponse('Authentication required.', 401);
        }

        $this->subscriptionRemoval->removeForUser(Uuid::fromString($user->getId()), $data['endpoint']);

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
            new OA\Response(response: '401', description: 'Authentication required'),
        ],
    )]
    #[Route('/subscriptions', name: 'remove_all', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removeAll(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->errorResponse('Authentication required.', 401);
        }

        $this->subscriptionRemoval->removeAllForUser(Uuid::fromString($user->getId()));

        return $this->noContent();
    }

    /**
     * @param array<string, mixed> $data
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
        foreach ($this->allowedPushDomains as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}
