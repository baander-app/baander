<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeJobDto;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;

interface TranscodePublicViewInterface
{
    public function videoId(PublicId $publicId): ?Uuid;

    public function session(TranscodeSession $session): TranscodeSessionDto;

    public function job(TranscodeJob $job): TranscodeJobDto;
}
