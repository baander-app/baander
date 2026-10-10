<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/** Where a component stands in the alert cycle; the values are stored in HealthAlertTable. */
enum HealthAlertState: int
{
    /** No alert is owed. */
    case Healthy = 0;
    /** An alert is owed and not yet delivered. */
    case Pending = 1;
    /** The alert was delivered, or dropped because admin alerts are off; the outage may continue. */
    case Acknowledged = 2;
}
