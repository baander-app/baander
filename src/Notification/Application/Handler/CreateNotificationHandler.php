<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Library\Application\Query\LibraryMembershipQueryPort;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Application\Service\NotificationContentResolver;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateNotificationHandler
{
    public function __construct(
        private readonly EventCategoryResolver $categoryResolver,
        private readonly NotificationContentResolver $contentResolver,
        private readonly TranslatorInterface $translator,
        private readonly NotificationRepositoryInterface $notificationRepository,
        private readonly LibraryMembershipQueryPort $libraryMembershipQuery,
        private readonly UserRepositoryInterface $userRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreateNotificationCommand $command): void
    {
        $category = $this->categoryResolver->resolve($command->eventClass);

        if ($category === null) {
            return;
        }

        $resolved = $this->contentResolver->resolve($category, $command->eventName, $command->payload);
        $referenceData = $this->extractReferenceData($command->eventName, $command->payload);
        $recipientUserIds = $this->resolveRecipients($command->eventName, $command->payload);

        // In-app, push and webhook text stays English; each email is translated when it is sent.
        $englishParameters = [
            'title' => $this->inEnglish($resolved['parameters']['title']),
            'body' => $this->inEnglish($resolved['parameters']['body']),
        ];
        $title = $this->translator->trans($resolved['titleKey'], $englishParameters['title'], 'notification', SupportedLanguages::FALLBACK);
        $body = $this->translator->trans($resolved['bodyKey'], $englishParameters['body'], 'notification', SupportedLanguages::FALLBACK);

        foreach ($recipientUserIds as $userIdString) {
            $userId = Uuid::fromString($userIdString);

            $notification = Notification::create(
                userId: $userId,
                category: $category,
                eventType: $command->eventName,
                title: $title,
                body: $body,
                referenceData: $referenceData,
                parameters: $englishParameters,
            );

            $this->notificationRepository->save($notification);

            $this->dispatchEmailCommand($userId, $category, $resolved, $notification);
            $this->dispatchPushCommand($userId, $category, $title, $body, $notification);
            $this->dispatchWebhookCommand($userId, $category, $title, $body, $notification);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function inEnglish(array $parameters): array
    {
        return array_map(
            fn (mixed $value): mixed => $value instanceof TranslatableInterface
                ? $value->trans($this->translator, SupportedLanguages::FALLBACK)
                : $value,
            $parameters,
        );
    }

    /**
     * @param array{titleKey: string, bodyKey: string, parameters: array{title: array<string, mixed>, body: array<string, mixed>}} $content
     */
    private function dispatchEmailCommand(
        Uuid $userId,
        NotificationCategory $category,
        array $content,
        Notification $notification,
    ): void {
        try {
            $user = $this->userRepository->findByUuid($userId);
        } catch (\Throwable) {
            return;
        }

        if ($user === null || !$user->isEmailVerified()) {
            return;
        }

        $this->bus->dispatch(new SendEmailCommand(
            userId: $userId,
            userEmail: $user->getEmail(),
            category: $category,
            titleKey: $content['titleKey'],
            titleParameters: $content['parameters']['title'],
            bodyKey: $content['bodyKey'],
            bodyParameters: $content['parameters']['body'],
            createdAt: $notification->getCreatedAt(),
            notificationPublicId: $notification->getPublicId()->toString(),
        ));
    }

    private function dispatchPushCommand(
        Uuid $userId,
        NotificationCategory $category,
        string $title,
        string $body,
        Notification $notification,
    ): void {
        $this->bus->dispatch(new SendPushCommand(
            userId: $userId,
            category: $category,
            title: $title,
            body: $body,
            notificationPublicId: $notification->getPublicId()->toString(),
        ));
    }

    private function dispatchWebhookCommand(
        Uuid $userId,
        NotificationCategory $category,
        string $title,
        string $body,
        Notification $notification,
    ): void {
        $this->bus->dispatch(new SendWebhookCommand(
            userId: $userId,
            category: $category,
            title: $title,
            body: $body,
            notificationPublicId: $notification->getPublicId()->toString(),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function resolveRecipients(string $eventName, array $payload): array
    {
        if ($eventName === 'library.scan_completed' && isset($payload['library_id'])) {
            return $this->libraryMembershipQuery->findUserIdsForLibrary(
                Uuid::fromString($payload['library_id']),
            );
        }

        if (isset($payload['user_id'])) {
            return [$payload['user_id']];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function extractReferenceData(string $eventName, array $payload): ?array
    {
        if ($eventName === 'library.scan_completed' && isset($payload['library_id'])) {
            return ['library_id' => $payload['library_id']];
        }

        if ($eventName === 'album.created' && isset($payload['album_id'])) {
            return ['album_id' => $payload['album_id']];
        }

        return null;
    }
}
