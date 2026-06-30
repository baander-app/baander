<?php

declare(strict_types=1);

namespace Tsduck\Exception;

/**
 * Exception thrown when TSProcessor::start() fails.
 *
 * The native tspyStartTSProcessor function returns false when the
 * processor cannot be started (e.g., invalid plugin configuration,
 * missing input/output, or resource allocation failure).
 */
class TSPStartException extends TsduckException
{
}
