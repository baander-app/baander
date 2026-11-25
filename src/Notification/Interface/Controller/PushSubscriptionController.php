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
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
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
        private readonly Security $security,
        private readonly PushSubscriptionRemovalPortInterface $subscriptionRemoval,
        private readonly array $allowedPushDomains,
    ) {
    }

    /**
     * Subscribe to browser push notifications.
     */
    #[OA\Operation\Post(
        path: '/api/push/subscribe',
        summary: 'Subscribe to push notifications',
        description: 'A user keeps at most 20 subscriptions; registering another evicts the longest-registered ones.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'endpoint' => new OA\Schema(
                            type: 'string',
                            maxLength: PushSubscriptionRegistrationPortInterface::ENDPOINT_LENGTH,
                        ),
                        'keys' => new OA\Schema(
                            type: 'object',
                            properties: [
                                'p256dh' => new OA\Schema(
                                    type: 'string',
                                    maxLength: PushSubscriptionRegistrationPortInterface::PUBLIC_KEY_LENGTH,
                                ),
                                'auth' => new OA\Schema(
                                    type: 'string',
                                    maxLength: PushSubscriptionRegistrationPortInterface::AUTH_KEY_LENGTH,
                                ),
                            ],
                        ),
                        'contentEncoding' => new OA\Schema(
                            type: 'string',
                            enum: ['aesgcm', 'aes128gcm'],
                        ),
                    ],
                    required: ['endpoint', 'keys', 'contentEncoding'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Updated owned subscription',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(
                                type: 'string',
                                enum: ['subscribed'],
                            ),
                        ],
                        required: ['status'],
                    ),
                ),
            ),
            new OA\Response(
                response: '201',
                description: 'Subscribed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(
                                type: 'string',
                                enum: ['subscribed'],
                            ),
                        ],
                        required: ['status'],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The body is not a JSON object',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(
                response: '409',
                description: 'Subscription unavailable',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid subscription',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->toArray();

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
            userAgent: self::userAgent($request),
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
    #[OA\Operation\Delete(
        path: '/api/push/subscribe',
        summary: 'Unsubscribe from push notifications',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'endpoint' => new OA\Schema(type: 'string'),
                    ],
                    required: ['endpoint'],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '204', description: 'Unsubscribed'),
            new OA\Response(
                response: '400',
                description: 'The body is not a JSON object',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(response: '401', description: 'Authentication required'),
            new OA\Response(response: '422', description: 'Invalid endpoint'),
        ],
    )]
    #[Route('/subscribe', name: 'unsubscribe', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->toArray();

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
    #[OA\Operation\Delete(
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
            'endpoint'        => new Assert\Sequentially([
                new Assert\Type('string'),
                new Assert\NotBlank(),
                new Assert\Length(max: PushSubscriptionRegistrationPortInterface::ENDPOINT_LENGTH),
                new Assert\Url(),
            ]),
            'keys'            => new Assert\Collection([
                'p256dh' => new Assert\Sequentially([
                    new Assert\Type('string'),
                    new Assert\NotBlank(),
                    new Assert\Length(max: PushSubscriptionRegistrationPortInterface::PUBLIC_KEY_LENGTH),
                ]),
                'auth'   => new Assert\Sequentially([
                    new Assert\Type('string'),
                    new Assert\NotBlank(),
                    new Assert\Length(max: PushSubscriptionRegistrationPortInterface::AUTH_KEY_LENGTH),
                ]),
            ]),
            'contentEncoding' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Choice(choices: ['aesgcm', 'aes128gcm'])]),
        ]);

        $violations = $this->validator->validate($data, $constraints);

        $errors = [];
        foreach ($violations as $violation) {
            $field = str_replace(['[', ']'], '', $violation->getPropertyPath());
            $errors[$field] = $violation->getMessage();
        }

        return $errors;
    }

    /** The client's User-Agent, kept as a label: invalid UTF-8 replaced and cut to the stored bound. */
    private static function userAgent(Request $request): ?string
    {
        $userAgent = $request->headers->get('User-Agent');
        if ($userAgent === null) {
            return null;
        }

        return mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, PushSubscriptionRegistrationPortInterface::USER_AGENT_LENGTH);
    }

    private function isAllowedPushDomain(string $host): bool
    {
        return array_any(
            $this->allowedPushDomains,
            static fn (string $allowedDomain): bool => $host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain),
        );
    }
}
