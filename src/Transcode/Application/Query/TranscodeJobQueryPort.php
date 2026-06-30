<?php

declare(strict_types=1);

namespace App\Transcode\Application\Query;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeJobDto;

interface TranscodeJobQueryPort
{
    public function findByPublicId(PublicId $publicId): ?TranscodeJobDto;

    /** @return TranscodeJobDto[] */
    public function findByUser(Uuid $userId): array;

    /** @return TranscodeJobDto[] */
    public function findActiveByUser(Uuid $userId): array;
}
