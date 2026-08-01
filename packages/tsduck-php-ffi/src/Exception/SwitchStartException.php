<?php

declare(strict_types=1);

namespace Tsduck\Exception;

/**
 * Exception thrown when InputSwitcher::start() fails.
 *
 * The native tspyStartInputSwitcher function returns false when the
 * input switcher cannot be started (e.g., invalid plugin configuration,
 * missing input plugins, or resource allocation failure).
 */
class SwitchStartException extends TsduckException
{
}
