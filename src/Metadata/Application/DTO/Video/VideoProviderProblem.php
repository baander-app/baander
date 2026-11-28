<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

enum VideoProviderProblem
{
    case Disabled;
    case NotConfigured;
    case Unavailable;
    case LoginFailed;
    /** The provider refused this title's request. Other titles may continue. */
    case Refused;
}
