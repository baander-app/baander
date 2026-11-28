<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\CommandHandler\MusicBrainzMatch\MatchTicks;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\PersonArtistLinkPortInterface;
use App\Catalog\Application\Port\QueuedArtistSyncsInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\MusicBrainzMatch\ArtistMatchWriter;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Port\ImdbPersonLookupInterface;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fills an artist's empty details from MusicBrainz, looked up by the MusicBrainz ID the artist
 * holds: sort name, type, country, disambiguation and the two ends of its life span. Its name, its
 * ID, every field that holds a value and every locked field stay as they are, and an artist
 * without an ID is left alone; nothing is looked up by name.
 *
 * It asks MusicBrainz only for an artist with an ID and an empty, unlocked field among those six,
 * and only when MusicBrainz did not answer about that ID within 30 days. The lookup runs before any
 * lock. Then, in one transaction, it locks the artist's row, reads the artist again and fills what
 * is still empty and unlocked as the artist is then, with the rule an automatic match fills by; an
 * artist removed or merged meanwhile, or given another ID, is not written. The row is its only
 * lock, so it cannot wait in a cycle with another writer. Nothing is saved when nothing is filled.
 *
 * MusicBrainz's answer, a record or a 404, counts for 30 days, also when the record is of another
 * artist because MusicBrainz merged this one into it: then nothing is written, and the stored ID
 * stays. The answer is recorded once the write committed, or at once when nothing is written, so a
 * write that fails is asked again when its message is retried. A refusal records nothing, since a
 * refusal can end. An outage queues the fill again after a pause of 5, 15 and then 60 minutes; at
 * the fourth outage in a row the fill ends and the next admin sync asks again. A fill whose admin
 * sync was cancelled ends without a request.
 *
 * Every expected outcome ends the message without an exception and is logged, so a sync adds no
 * failed job to the metadata status.
 */
final readonly class SyncArtistHandler
{
    /** The pause before a fill that met an outage is queued again, by the outages it met in a row before. */
    private const array OUTAGE_PAUSES_MS = [300_000, 900_000, 3_600_000];

    /** The fields an artist sync fills. */
    private const array DETAILS = [
        MatchField::SortName, MatchField::Type, MatchField::Country,
        MatchField::Disambiguation, MatchField::LifeSpanBegin, MatchField::LifeSpanEnd,
    ];

    public function __construct(
        private ArtistPortInterface $artists,
        private ArtistMergePortInterface $merges,
        private MusicBrainzLookupInterface $musicBrainz,
        private MatchComparisonBuilder $comparisons,
        private ArtistMatchWriter $writer,
        private QueuedArtistSyncsInterface $marks,
        private ArtistSyncQueue $queue,
        private TransactionPortInterface $transaction,
        private LoggerInterface $logger,
        private ?PersonArtistLinkPortInterface $personLinks = null,
        private ?ImdbPersonLookupInterface $imdb = null,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SyncArtistMessage $message): void
    {
        $queuedAgain = $message->runId !== null && $this->marks->isRunCancelled($message->runId)
            ? $this->cancelled($message)
            : $this->sync($message);
        // A sync queued again keeps the artist marked; an exception keeps it too, for the retry.
        if (!$queuedAgain) {
            $this->marks->clearQueued($message->artistId);
        }
    }

    /** @return bool whether the fill was queued again */
    private function sync(SyncArtistMessage $message): bool
    {
        $artistId = $message->artistId;
        $artist = $this->artists->findFreshByUuid($artistId);
        $musicbrainzId = MusicbrainzId::tryFromString($artist?->getMbid())?->toString();
        if ($artist === null || $musicbrainzId === null || $this->marks->isAnswered($artistId, $musicbrainzId)) {
            return false;
        }
        if (!self::missesDetails($artist) && !$this->personLinks?->needsReconciliation($artistId, $musicbrainzId, $artist->getType())) {
            return false;
        }

        $answer = $this->musicBrainz->fetchArtist($musicbrainzId, MusicBrainzSlotWait::Unbounded);
        $context = ['artist_id' => $artistId->toString(), 'mbid' => $musicbrainzId];

        return match (true) {
            $answer instanceof MusicBrainzDisabled => false,
            $answer instanceof MusicBrainzUnavailable => $this->afterOutage($message, $context + ['reason' => $answer->reason]),
            $answer instanceof MusicBrainzRejected => $this->rejected($context + ['status' => $answer->status]),
            $answer === null => $this->notFound($artistId, $musicbrainzId, $context),
            $answer instanceof MusicBrainzMerged => $this->answeredForAnotherId($artistId, $musicbrainzId, $answer->survivingId, $context),
            !self::sameId($answer->id, $musicbrainzId) => $this->answeredForAnotherId($artistId, $musicbrainzId, $answer->id, $context),
            default => $this->fill($message, $musicbrainzId, $answer, $context),
        };
    }

    /**
     * Writes, under the artist's row lock, the details MusicBrainz has for the fields still empty
     * and unlocked as the artist is then.
     *
     * @param array<string, string> $context
     */
    private function fill(SyncArtistMessage $message, string $musicbrainzId, MusicBrainzArtist $answer, array $context): bool
    {
        $artistId = $message->artistId;
        $bridgeUnavailable = false;
        $ids = $answer->personIdentifiers;
        $imdb = $ids['imdb_name'] ?? null;
        if ($answer->type === 'Person' && $imdb !== null) {
            try {
                $tmdb = $this->imdb?->findTmdbPersonId($imdb);
                if (is_string($tmdb)) {
                    $ids['tmdb_person'] = $tmdb;
                }
            } catch (\RuntimeException) {
                $bridgeUnavailable = true;
            }
        }

        $outcome = $this->transaction->run(function () use ($artistId, $musicbrainzId, $answer, $ids, $bridgeUnavailable): string|array {
            if ($this->merges->lock([$artistId]) === []) {
                return 'gone';
            }
            $artist = $this->artists->findFreshByUuid($artistId) ?? throw new \LogicException('A locked artist exists.');
            if (MusicbrainzId::tryFromString($artist->getMbid())?->toString() !== $musicbrainzId) {
                return 'reidentified';
            }
            $fills = MatchTicks::automatic($this->comparisons->artist($artist, $answer), ofMatchedAlbum: false)->fields
                |> (static fn (array $rows): array => array_filter($rows, static fn (MatchFieldRow $row): bool => in_array($row->field, self::DETAILS, true)))
                |> array_values(...);
            if ($fills !== []) {
                $this->writer->write($artist, $fills);
            }

            if ($answer->type === 'Person' && !$bridgeUnavailable) {
                $this->personLinks?->reconcile($artistId, $musicbrainzId, $ids, [
                    'name' => $answer->name,
                    'aliases' => $answer->aliases,
                    'biography' => $artist->getBiography(),
                    'birth_date' => $answer->lifeSpanBegin,
                    'death_date' => $answer->lifeSpanEnd,
                    'is_deceased' => $answer->ended,
                    'birthplace' => $answer->birthplace,
                    'gender' => $answer->gender,
                ]);
            }

            return array_map(static fn (MatchFieldRow $row): string => $row->field->value, $fills);
        });

        match ($outcome) {
            'gone' => $this->logger->info('The artist was removed or merged during its MusicBrainz lookup; nothing was filled.', $context),
            'reidentified' => $this->logger->info('The artist was given another MusicBrainz ID during its lookup; nothing was filled.', $context),
            [] => $this->logger->info('MusicBrainz has none of the details the artist misses.', $context),
            default => $this->logger->info('Filled the artist\'s empty details from MusicBrainz.', $context + ['fields' => $outcome]),
        };

        if ($bridgeUnavailable && is_array($outcome)) {
            return $this->afterOutage($message, $context + ['reason' => 'tmdb-identity-bridge'], 'TMDB');
        }

        return $this->answered($artistId, $musicbrainzId);
    }

    /** @param array<string, string> $context */
    private function notFound(Uuid $artistId, string $musicbrainzId, array $context): bool
    {
        $this->logger->info('MusicBrainz has no artist with the ID the artist holds; nothing was filled.', $context);

        return $this->answered($artistId, $musicbrainzId);
    }

    /**
     * MusicBrainz merged the artist's ID into another artist, or answered it with another artist.
     * Nothing is written and no artist is merged: an admin re-identifies the artist by a match.
     *
     * @param array<string, string> $context
     */
    private function answeredForAnotherId(Uuid $artistId, string $musicbrainzId, string $answeredId, array $context): bool
    {
        $this->logger->warning('MusicBrainz answered the artist\'s ID with another artist; nothing was filled and the stored ID stays.', $context + ['answered_id' => $answeredId]);

        return $this->answered($artistId, $musicbrainzId);
    }

    /** Records that MusicBrainz answered about the ID, for 30 days. */
    private function answered(Uuid $artistId, string $musicbrainzId): bool
    {
        $this->marks->markAnswered($artistId, $musicbrainzId, ArtistSyncQueue::ANSWER_TTL_SECONDS);

        return false;
    }

    /** @param array<string, string|int> $context */
    private function rejected(array $context): bool
    {
        $this->logger->warning('MusicBrainz refused the lookup of the artist\'s ID; nothing was filled, and the next sync of the artist asks again.', $context);

        return false;
    }

    /**
     * Queues the fill again after a pause, or ends it at the fourth outage in a row.
     *
     * @param array<string, string> $context
     */
    private function afterOutage(SyncArtistMessage $message, array $context, string $provider = 'MusicBrainz'): bool
    {
        $pauseMs = self::OUTAGE_PAUSES_MS[$message->outages] ?? null;
        if ($pauseMs === null) {
            $this->logger->warning('{provider} was unavailable for the artist\'s lookup again; the next admin sync asks again.', $context + ['provider' => $provider, 'outages' => $message->outages + 1]);

            return false;
        }
        $delayMs = $this->queue->queueAfterOutage($message, $pauseMs);
        $this->logger->warning('{provider} was unavailable for the artist\'s lookup; the fill is queued again.', $context + ['provider' => $provider, 'outages' => $message->outages + 1, 'delay_ms' => $delayMs]);

        return true;
    }

    private function cancelled(SyncArtistMessage $message): bool
    {
        $this->logger->info('The admin sync that queued the artist\'s fill was cancelled; skipping the artist.', [
            'artist_id' => $message->artistId->toString(),
            'run_id' => $message->runId?->toString(),
        ]);

        return false;
    }

    /** Whether one of the details an artist sync fills is empty and unlocked. */
    private static function missesDetails(Artist $artist): bool
    {
        $values = [
            MatchField::SortName->value => $artist->getSortName(),
            MatchField::Type->value => $artist->getType(),
            MatchField::Country->value => $artist->getCountry(),
            MatchField::Disambiguation->value => $artist->getDisambiguation(),
            MatchField::LifeSpanBegin->value => $artist->getLifeSpanBegin(),
            MatchField::LifeSpanEnd->value => $artist->getLifeSpanEnd(),
        ];

        return array_any($values, static fn (mixed $value, string $field): bool => $value === null && !$artist->isFieldLocked($field));
    }

    private static function sameId(string $answered, string $stored): bool
    {
        return MusicbrainzId::tryFromString($answered)?->toString() === $stored;
    }
}
