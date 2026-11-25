<?php

declare(strict_types=1);

namespace App\Notification\Domain\Service;

use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Event\OAuth\TokenRevoked;
use App\Auth\Domain\Event\Passkey\PasskeyDeleted;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Event\UserRegistered;
use App\Catalog\Domain\Event\AlbumCreated;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeJobFailed;

final class EventCategoryResolver
{
    /**
     * Maps domain event class names (or event names) to notification categories.
     *
     * @var array<string, NotificationCategory>
     */
    private const EVENT_MAP = [
        // Security events
        PasswordChanged::class => NotificationCategory::Security,
        PasskeyRegistered::class => NotificationCategory::Security,
        PasskeyDeleted::class => NotificationCategory::Security,
        TokenRevoked::class => NotificationCategory::Security,
        DeviceCodeApproved::class => NotificationCategory::Security,
        UserRegistered::class => NotificationCategory::Security,

        // Background Jobs events
        LibraryScanCompleted::class => NotificationCategory::BackgroundJobs,
        TranscodeJobCompleted::class => NotificationCategory::BackgroundJobs,
        TranscodeJobFailed::class => NotificationCategory::BackgroundJobs,

        // Media Changes events
        AlbumCreated::class => NotificationCategory::MediaChanges,

        // Excluded from notifications (not in the map):
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
