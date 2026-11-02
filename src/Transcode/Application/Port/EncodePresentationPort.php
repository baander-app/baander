<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Domain\ValueObject\PresentationSelection;

interface EncodePresentationPort
{
    public function select(CreateTranscodeSessionCommand $command): PresentationSelection;
}
