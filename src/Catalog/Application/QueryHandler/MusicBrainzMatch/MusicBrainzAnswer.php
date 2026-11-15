<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;

/**
 * Reports a MusicBrainz outage or refusal as the shared exceptions, told apart by `details.reason`:
 * an outage answers 503 with `musicbrainz_unavailable`, so a caller never mistakes it for an empty
 * result, and a refused request answers 409 with `musicbrainz_rejected`. A candidate MusicBrainz
 * does not have answers 404 with `candidate_not_found`, and so does one it merged into another:
 * it has no candidate under that ID any more.
 */
final class MusicBrainzAnswer
{
    public const string UNAVAILABLE_REASON = 'musicbrainz_unavailable';
    public const string REJECTED_REASON = 'musicbrainz_rejected';
    public const string CANDIDATE_NOT_FOUND_REASON = 'candidate_not_found';

    /**
     * @template T
     *
     * @param T|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable $answer
     *
     * @return T|null the answer itself when MusicBrainz gave one; null for an ID it merged into another
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
            $answer instanceof MusicBrainzMerged => null,
            default => $answer,
        };
    }

    /**
     * MusicBrainz has no candidate of the ID an admin gave. The message names no ID: a MusicBrainz ID
     * looks like an internal one, which the API's error responses mask, and the console must print
     * what the API answers.
     */
    public static function unknownCandidate(MatchItemType $type): NotFoundException
    {
        return new NotFoundException(sprintf('MusicBrainz has no such %s.', $type->candidateNoun()), ['reason' => self::CANDIDATE_NOT_FOUND_REASON]);
    }
}
