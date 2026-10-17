<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeJobDto;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;

final class PublicViewStub implements TranscodePublicViewInterface
{
    public function __construct(private ?Uuid $resolvedVideoId = null)
    {
    }

    public function videoId(PublicId $publicId): ?Uuid
    {
        return $this->resolvedVideoId;
    }

    public function session(TranscodeSession $session): TranscodeSessionDto
    {
        return TranscodeSessionDto::fromModel($session, new PublicId(str_repeat('u', 21)), new PublicId(str_repeat('j', 21)), new PublicId(str_repeat('v', 21)));
    }

    public function job(TranscodeJob $job): TranscodeJobDto
    {
        return TranscodeJobDto::fromModel($job, new PublicId(str_repeat('v', 21)));
    }
}
