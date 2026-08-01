<?php

declare(strict_types=1);

namespace App\Notification\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Interface\Resource\NotificationResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[OA\Tag(name: 'Notifications', description: 'Notification management endpoints')]
#[Route('/api/notifications', name: 'notification_')]
final class NotificationController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * List authenticated user's notifications.
     */
    #[OA\Get(
        path: '/api/notifications/',
        summary: 'List notifications',
        parameters: [
            new OA\Parameter(name: 'category', description: 'Filter by category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['security', 'background_jobs', 'media_changes', 'admin_operations'])),
            new OA\Parameter(name: 'unread', description: 'Filter unread only', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'limit', description: 'Items per page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'cursor', description: 'Cursor for pagination', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'since', description: 'ISO 8601 timestamp for polling fallback', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'List of notifications', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'eventType', type: 'string'), new OA\Property(property: 'isRead', type: 'boolean'), new OA\Property(property: 'createdAt', type: 'string', format: 'date-time')]))])),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $category = null;
        $categoryParam = $request->query->get('category');
        if ($categoryParam !== null) {
            $category = NotificationCategory::tryFrom($categoryParam);
            if ($category === null) {
                return $this->errorResponse(
                    sprintf('Invalid category: %s.', $categoryParam),
                    400,
                );
            }
        }

        $unreadOnly = $request->query->getBoolean('unread', false);
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $cursor = $request->query->get('cursor');

        $since = null;
        $sinceParam = $request->query->get('since');
        if ($sinceParam !== null) {
            try {
                $since = new \DateTimeImmutable($sinceParam);
            } catch (\Throwable) {
                return $this->errorResponse('Invalid "since" timestamp format.', 400);
            }
        }

        $notifications = $this->notificationRepository->findByUserId(
            $userId,
            $category,
            $unreadOnly ?: null,
            $limit,
            $cursor,
        );

        $items = NotificationResource::collection($notifications);

        foreach ($items as $i => $item) {
            if ($item['parameters'] !== null) {
                $items[$i]['title'] = $this->trans(
                    sprintf('%s.title', $item['eventType']),
                    $item['parameters']['title'] ?? [],
                    'notification',
                );
                $items[$i]['body'] = $this->trans(
                    sprintf('%s.body', $item['eventType']),
                    $item['parameters']['body'] ?? [],
                    'notification',
                );
            }
        }

        return $this->successResponse($items);
    }

    /**
     * Get unread notification count.
     */
    #[OA\Get(
        path: '/api/notifications/unread-count',
        summary: 'Get unread notification count',
        responses: [
            new OA\Response(response: '200', description: 'Unread count', content: new OA\JsonContent(properties: [new OA\Property(property: 'count', type: 'integer')])),
        ],
    )]
    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unreadCount(): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $count = $this->notificationRepository->countUnread($userId);

        return $this->successResponse(['count' => $count]);
    }

    /**
     * Mark a single notification as read.
     */
    #[OA\Patch(
        path: '/api/notifications/{publicId}/read',
        summary: 'Mark notification as read',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Notification public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Notification marked as read', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'publicId', type: 'string'), new OA\Property(property: 'isRead', type: 'boolean')])])),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/read', name: 'mark_read', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function markRead(string $publicId): JsonResponse
    {
        $notification = $this->findNotification($publicId);
        if ($notification === null) {
            return $this->notFound();
        }

        if (!$notification->isRead()) {
            $this->notificationRepository->markAsRead($notification->getId());
            $notification->markAsRead();
        }

        $item = NotificationResource::from($notification);

        if ($item['parameters'] !== null) {
            $item['title'] = $this->trans(
                sprintf('%s.title', $item['eventType']),
                $item['parameters']['title'] ?? [],
                'notification',
            );
            $item['body'] = $this->trans(
                sprintf('%s.body', $item['eventType']),
                $item['parameters']['body'] ?? [],
                'notification',
            );
        }

        return $this->successResponse($item);
    }

    /**
     * Mark all notifications as read.
     */
    #[OA\Patch(
        path: '/api/notifications/read-all',
        summary: 'Mark all notifications as read',
        responses: [
            new OA\Response(response: '200', description: 'All notifications marked as read', content: new OA\JsonContent(properties: [new OA\Property(property: 'marked', type: 'boolean')])),
        ],
    )]
    #[Route('/read-all', name: 'mark_all_read', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function markAllRead(): JsonResponse
    {
        /** @var SecurityUser $user */
        $user = $this->security->getUser();
        $userId = Uuid::fromString($user->getId());

        $this->notificationRepository->markAllAsRead($userId);

        return $this->successResponse(['marked' => true]);
    }

    /**
     * Delete a notification.
     */
    #[OA\Delete(
        path: '/api/notifications/{publicId}',
        summary: 'Delete a notification',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Notification public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(string $publicId): JsonResponse
    {
        $notification = $this->findNotification($publicId);
        if ($notification === null) {
            return $this->notFound();
        }

        $this->notificationRepository->delete($notification);

        return $this->noContent();
    }

    private function findNotification(string $publicId): ?\App\Notification\Domain\Model\Notification
    {
        try {
            PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        return $this->notificationRepository->findByPublicId($publicId);
    }
}
