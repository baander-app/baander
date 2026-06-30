<?php

declare(strict_types=1);

namespace App\Transcode\Application\Query;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeSessionDto;

interface TranscodeSessionQueryPort
{
    public function findByPublicId(PublicId $publicId): ?TranscodeSessionDto;

    /** @return TranscodeSessionDto[] */
    public function findByUser(Uuid $userId): array;
}
