<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Domain\Model\VideoMatchState;

interface AutomaticVideoMatcherInterface
{
    public function match(VideoMatchState $claim): VideoMatchOutcome;
}
