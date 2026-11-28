<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

enum VideoMatchOutcomeKind
{
    case Matched;
    case NeedsReview;
    case AwaitingProvider;
    case RetryLater;
    case Unavailable;
    case Gone;
}
