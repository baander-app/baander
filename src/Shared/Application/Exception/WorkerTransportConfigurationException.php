<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/** Configuration diagnostics contain only the setting and rule, never supplied values. */
final class WorkerTransportConfigurationException extends \InvalidArgumentException
{
}
