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

    /**
     * The notification-domain message key of the email header, which takes the `appName` parameter.
     */
    public function headerTitle(): string
    {
        return match ($this) {
            self::Security => 'email.header.security',
            self::BackgroundJobs,
            self::MediaChanges,
            self::AdminOperations => 'email.header.default',
        };
    }
}
