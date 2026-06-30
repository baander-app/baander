<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioStation;

final readonly class Stream
{
    public function __construct(
        public string $url,
        public string $format,
        public int $bitrate,
        public float $reliability,
    ) {
    }
}
