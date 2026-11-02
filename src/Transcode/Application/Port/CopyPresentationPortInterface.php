<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\CopyPresentation;
use App\Transcode\Application\DTO\CopyPresentationInput;

interface CopyPresentationPortInterface
{
    public function start(CopyPresentationInput $input): CopyPresentation;
}
