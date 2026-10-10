<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/** What became of one attempt to deliver a pending health alert. */
enum HealthAlertDelivery
{
    /** The alert was saved for the administrators. */
    case Delivered;
    /** `notifications.admin_alerts` is off: the alert was logged and dropped. */
    case Disabled;
    /** Reading the setting or saving the alert threw; the alert stays owed. */
    case Failed;
}
