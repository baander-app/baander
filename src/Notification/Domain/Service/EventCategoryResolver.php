<?php

declare(strict_types=1);

namespace App\Notification\Domain\Service;

use App\Notification\Domain\ValueObject\NotificationCategory;

final class EventCategoryResolver
{
    /**
     * Maps domain event class names (or event names) to notification categories.
     *
     * @var array<string, NotificationCategory>
     */
    private const EVENT_MAP = [
        // Security events
        \App\Auth\Domain\Event\PasswordChanged::class => NotificationCategory::Security,
        \App\Auth\Domain\Event\Passkey\PasskeyRegistered::class => NotificationCategory::Security,
        \App\Auth\Domain\Event\Passkey\PasskeyDeleted::class => NotificationCategory::Security,
        \App\Auth\Domain\Event\OAuth\TokenRevoked::class => NotificationCategory::Security,
        \App\Auth\Domain\Event\OAuth\DeviceCodeApproved::class => NotificationCategory::Security,
        \App\Auth\Domain\Event\UserRegistered::class => NotificationCategory::Security,

        // Background Jobs events
        \App\Library\Domain\Event\LibraryScanCompleted::class => NotificationCategory::BackgroundJobs,
        \App\Transcode\Domain\Event\TranscodeJobCompleted::class => NotificationCategory::BackgroundJobs,
        \App\Transcode\Domain\Event\TranscodeJobFailed::class => NotificationCategory::BackgroundJobs,

        // Media Changes events
        \App\Catalog\Domain\Event\AlbumCreated::class => NotificationCategory::MediaChanges,

        // Excluded from notifications (not in the map):
        // - TokenIssued (too noisy, R6)
        // - MetadataSynced (async context, no user)
        // - SongMetadataUpdated (async context, no user)
        // - SmartPlaylistSynced (low priority, deferred)
        // - PlaylistCreated (low priority, deferred)
    ];

    public function resolve(string $eventClass): ?NotificationCategory
    {
        return self::EVENT_MAP[$eventClass] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getMappedEventClasses(): array
    {
        return array_keys(self::EVENT_MAP);
    }
}
