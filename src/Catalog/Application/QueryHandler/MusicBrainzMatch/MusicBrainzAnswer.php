<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ServiceUnavailableException;

/**
 * Reports a MusicBrainz outage or refusal as the shared exceptions, told apart by `details.reason`:
 * an outage answers 503 with `musicbrainz_unavailable`, so a caller never mistakes it for an empty
 * result, and a refused request answers 409 with `musicbrainz_rejected`.
 */
final class MusicBrainzAnswer
{
    public const string UNAVAILABLE_REASON = 'musicbrainz_unavailable';
    public const string REJECTED_REASON = 'musicbrainz_rejected';

    /**
     * @template T
     *
     * @param T|MusicBrainzRejected|MusicBrainzUnavailable $answer
     *
     * @return T the answer itself when MusicBrainz gave one
     *
     * @throws ServiceUnavailableException when MusicBrainz gave no usable answer
     * @throws ConflictException           when MusicBrainz refused the request
     */
    public static function unwrap(mixed $answer): mixed
    {
        return match (true) {
            $answer instanceof MusicBrainzUnavailable => throw new ServiceUnavailableException(
                'MusicBrainz is unavailable. Try again later.',
                ['reason' => self::UNAVAILABLE_REASON],
            ),
            $answer instanceof MusicBrainzRejected => throw new ConflictException(
                sprintf('MusicBrainz rejected the request with HTTP %d.', $answer->status),
                ['reason' => self::REJECTED_REASON],
            ),
            default => $answer,
        };
    }
}
