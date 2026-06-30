<?php

declare(strict_types=1);

namespace App\Notification\Domain\ValueObject;

enum NotificationCategory: string
{
    case Security = 'security';
    case BackgroundJobs = 'background_jobs';
    case MediaChanges = 'media_changes';
    case AdminOperations = 'admin_operations';

    public function headerColor(): string
    {
        return match ($this) {
            self::Security => '#1a1a2e',
            self::BackgroundJobs => '#16213e',
            self::MediaChanges => '#0f3460',
            self::AdminOperations => '#1a2744',
        };
    }

    public function headerTitle(string $appName): string
    {
        return match ($this) {
            self::Security => "$appName Security Alert",
            self::BackgroundJobs,
            self::MediaChanges,
            self::AdminOperations => $appName,
        };
    }
}
