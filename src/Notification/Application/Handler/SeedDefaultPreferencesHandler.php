<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SeedDefaultPreferencesHandler
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SeedDefaultPreferencesCommand $command): void
    {
        // R24 defaults: All categories enabled for InApp. Security also enabled for Email.
        $defaults = [
            [NotificationCategory::Security, NotificationChannel::InApp, true],
            [NotificationCategory::Security, NotificationChannel::Email, true],
            [NotificationCategory::Security, NotificationChannel::Push, false],
            [NotificationCategory::Security, NotificationChannel::Webhook, false],
            [NotificationCategory::BackgroundJobs, NotificationChannel::InApp, true],
            [NotificationCategory::BackgroundJobs, NotificationChannel::Email, false],
            [NotificationCategory::BackgroundJobs, NotificationChannel::Push, false],
            [NotificationCategory::BackgroundJobs, NotificationChannel::Webhook, false],
            [NotificationCategory::MediaChanges, NotificationChannel::InApp, true],
            [NotificationCategory::MediaChanges, NotificationChannel::Email, false],
            [NotificationCategory::MediaChanges, NotificationChannel::Push, false],
            [NotificationCategory::MediaChanges, NotificationChannel::Webhook, false],
        ];

        foreach ($defaults as [$category, $channel, $enabled]) {
            $this->preferenceRepository->save(
                NotificationPreference::create(
                    userId: $command->userId,
                    category: $category,
                    channel: $channel,
                    enabled: $enabled,
                ),
            );
        }
    }
}
