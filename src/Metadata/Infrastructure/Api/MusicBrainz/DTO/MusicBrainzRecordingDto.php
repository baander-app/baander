<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz\DTO;

/**
 * Immutable DTO representing a MusicBrainz recording.
 */
final readonly class MusicBrainzRecordingDto
{
    /**
     * @param string $id MusicBrainz ID (MBID)
     * @param string $title Recording title
     * @param int|null $length Duration in milliseconds
     * @param string $artistCredit Human-readable artist credit string
     * @param string|null $releaseId MBID of the preferred release
     * @param string|null $releaseTitle Title of the preferred release
     * @param int|null $trackNumber Track number within the release
     * @param string[] $tags User-applied tags
     * @param int $score Search relevance score (0 when not from a search)
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?int $length = null,
        public string $artistCredit = '',
        public ?string $releaseId = null,
        public ?string $releaseTitle = null,
        public ?int $trackNumber = null,
        public array $tags = [],
        public int $score = 0,
    ) {
    }
}
