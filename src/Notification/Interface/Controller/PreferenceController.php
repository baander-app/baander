<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Notification\Interface\Request\UpdatePreferenceRequest;
use App\Notification\Interface\Resource\NotificationPreferenceResource;
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
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[OA\Tag(name: 'Notification Preferences', description: 'User notification preference management')]
#[Route('/api/notifications/preferences', name: 'notification_preference_')]
final class PreferenceController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly ValidatorInterface $validator,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Security $security,
    )
    {
    }

    /**
     * Get all notification preferences for the authenticated user.
     */
    #[OA\Get(
        path: '/api/notifications/preferences/',
        description: 'Returns all category × channel preference combinations for the authenticated user.',
        summary: 'Get notification preferences',
        responses: [
            new OA\Response(response: '200', description: 'List of preferences', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'category', type: 'string'), new OA\Property(property: 'channel', type: 'string'), new OA\Property(property: 'enabled', type: 'boolean')]))])),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $existing = $this->preferenceRepository->findByUserId($userId);
        $existingMap = $this->buildPreferenceMap($existing);

        $allPreferences = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (NotificationChannel::cases() as $channel) {
                $key = $category->value . ':' . $channel->value;
                $enabled = $existingMap[$key] ?? $this->getDefaultEnabled($category, $channel);

                $allPreferences[] = [
                    'category'  => $category->value,
                    'channel'   => $channel->value,
                    'enabled'   => $enabled,
                    'updatedAt' => $existingMap[$key] !== null ? null : null,
                ];
            }
        }

        // Merge existing updatedAt values
        foreach ($existing as $pref) {
            $key = $pref->getCategory()->value . ':' . $pref->getChannel()->value;
            foreach ($allPreferences as $i => $p) {
                if ($p['category'] . ':' . $p['channel'] === $key) {
                    $allPreferences[$i]['updatedAt'] = $pref->getUpdatedAt()->format(\DateTimeInterface::ATOM);
                }
            }
        }

        return $this->successResponse($allPreferences);
    }

    /**
     * Batch update notification preferences.
     */
    #[OA\Put(
        path: '/api/notifications/preferences/',
        summary: 'Update notification preferences',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['preferences'],
                    properties: [
                        new OA\Property(
                            property: 'preferences',
                            type: 'array',
                            items: new OA\Items(
                                required: ['category', 'channel', 'enabled'],
                                properties: [
                                    new OA\Property(property: 'category', type: 'string', enum: ['security', 'background_jobs', 'media_changes']),
                                    new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email', 'push', 'webhook']),
                                    new OA\Property(property: 'enabled', type: 'boolean'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Preferences updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'boolean')])),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'update', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(Request $request): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $data = $this->jsonEncoder->decode((string)$request->getContent(), 'json');
        $dto = new UpdatePreferenceRequest($data['preferences'] ?? []);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->errorResponse('Invalid preference data.', 422);
        }

        foreach ($dto->preferences as $item) {
            $category = NotificationCategory::from($item['category']);
            $channel = NotificationChannel::from($item['channel']);

            $existing = $this->preferenceRepository->findByUserAndCategoryAndChannel(
                $userId,
                $category,
                $channel,
            );

            if ($existing !== null) {
                if ($item['enabled']) {
                    $existing->enable();
                } else {
                    $existing->disable();
                }
                $this->preferenceRepository->save($existing);
            } else {
                $preference = NotificationPreference::create(
                    userId: $userId,
                    category: $category,
                    channel: $channel,
                    enabled: $item['enabled'],
                );
                $this->preferenceRepository->save($preference);
            }
        }

        return $this->successResponse(['updated' => true]);
    }

    /**
     * @param list<NotificationPreference> $preferences
     * @return array<string, bool>
     */
    private function buildPreferenceMap(array $preferences): array
    {
        $map = [];
        foreach ($preferences as $pref) {
            $key = $pref->getCategory()->value . ':' . $pref->getChannel()->value;
            $map[$key] = $pref->isEnabled();
        }

        return $map;
    }

    private function getDefaultEnabled(NotificationCategory $category, NotificationChannel $channel): bool
    {
        // R24: All categories enabled for InApp. Security also enabled for Email.
        if ($channel === NotificationChannel::InApp) {
            return true;
        }

        if ($category === NotificationCategory::Security && $channel === NotificationChannel::Email) {
            return true;
        }

        return false;
    }
}
