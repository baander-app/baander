<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/** The song metadata a lyrics provider matches on. Missing values stay null. */
final readonly class SongLyricSignature
{
    public function __construct(
        public string $title,
        public ?string $artistName,
        public ?string $albumTitle,
        public ?float $duration,
    ) {
    }
}
