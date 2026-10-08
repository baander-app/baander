<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Exception;

/** A disabled job can only be enabled; pausing or resuming it is refused. */
final class DisabledScheduledJob extends \DomainException
{
}
