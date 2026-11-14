<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumType;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\VideoProbePortInterface;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * @phpstan-type TaggedCredit array{identity: ArtistIdentity, creditedName: string}
 * @phpstan-type TagFields array{title: string, track: int|null, disc: int|null, year: int|null, comment: string|null}
 * @phpstan-type FileTags array{fields: TagFields, credit: TaggedCredit|null, albumArtist: TaggedCredit|null, genres: list<string>}
 * @phpstan-type Retag array{fields: TagFields, probe: AudioProbeResult}
 * @phpstan-type PreparedSong array{file: DiscoveredFile, song: Song, credit: TaggedCredit|null, albumArtist: TaggedCredit|null, genres: list<string>, known: bool, previousPath?: string, probe?: AudioProbeResult|null, retag?: Retag, repaired?: bool}
 */
final class FilesDiscoveredHandler
{
    private const int BATCH_SIZE = 50;

    /** How long an import waits before asking again whether a delete with files still holds its library. */
    private const int HELD_LIBRARY_RETRY_DELAY_MS = 30_000;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly GenrePortInterface $genreService,
        private readonly SongPortInterface $songService,
        private readonly MoviePortInterface $movieService,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly MetadataContentReaderPortInterface $metadataReader,
        private readonly VideoProbePortInterface $ffprobeAdapter,
        private readonly MessageBusInterface $messageBus,
        private readonly LyricsFetchRequestInterface $lyricsFetch,
        private readonly AlbumMetadataSyncRequestInterface $albumMetadataSync,
        private readonly LoggerInterface $logger,
        private readonly LibraryMediaFilesInterface $mediaFiles,
        private readonly TransactionPortInterface $transaction,
        private readonly AudioProbePortInterface $audioProbe,
        private readonly ArtistIdentityResolverInterface $artistIdentities,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(FilesDiscovered $message): void
    {
        // A delete with files that holds the library may be about to unlink some of these files,
        // and a song imported from one now would stay in the catalog without its file. Wait for
        // the delete to end. Each write checks again, for a delete that claims the library later.
        if ($this->mediaFiles->isHeldByDelete($message->libraryId)) {
            $this->requeueWhileHeld($message);

            return;
        }

        // Files deleted since the scan found them become no song, video or album.
        $files = $this->presentFiles($message);
        if ($files === []) {
            return;
        }

        match ($message->libraryType) {
            'music' => $this->processMusicFiles($message, $files),
            'movie' => $this->processMovieFiles($message, $files),
            default => throw new RuntimeException(sprintf('Unknown library type: %s', $message->libraryType)),
        };
    }

    /**
     * Reads the metadata of each batch of files first, then writes the batch's songs, artist
     * credits and genres in one transaction during which no delete with files can claim the
     * library. When a delete claimed it before that transaction, the import stops and the files
     * whose songs no transaction committed go back to the queue, as a held import does. After the
     * last batch, and before the import requeues or reports a failed file, each album the message
     * touched is given an artist credit when it has none.
     *
     * A file whose content a stored song holds repairs that song instead of becoming a new one, and
     * the song stays in the album it is stored in. A file with content no song holds, at the path of
     * a stored song, was retagged: it updates that song, which also stays in its album. An album is
     * resolved, or created, only for new songs, so a rescan after an album was renamed or merged, or
     * a retagged file, creates no album.
     *
     * @param list<DiscoveredFile> $files the message's files that still exist
     */
    private function processMusicFiles(FilesDiscovered $message, array $files): void
    {
        $libraryId = $message->libraryId;
        $failures = [];
        // Committed songs without sidecar lyrics, waiting for a successful lyrics request.
        $songsAwaitingLyrics = [];
        /** @var array<string, true> $committedPaths */
        $committedPaths = [];

        $audioFiles = array_values(array_filter($files, static fn (DiscoveredFile $file): bool => $file->isAudio()));
        // What each file becomes: a repair of the stored song its content belongs to; else an update
        // of the song stored at its path; else a new song in the album its tags name.
        $targets = $this->knownSongs($audioFiles);
        $retagged = $this->retaggedSongs($libraryId, array_values(array_filter($audioFiles, static fn (DiscoveredFile $file): bool => !isset($targets[$file->absolutePath]))));
        $targets += $retagged;
        $newFiles = array_values(array_filter($audioFiles, static fn (DiscoveredFile $file): bool => !isset($targets[$file->absolutePath])));
        $album = null;
        if ($newFiles !== []) {
            $resolved = $this->resolveAlbum($libraryId, $message->directory, $newFiles);
            if ($resolved === null) {
                $this->requeueWhileHeld($message);

                return;
            }
            [$album, $wasCreated] = $resolved;
            // resolveAlbum() has committed a new album. A retry or rescan finds it
            // and creates none, so request its sync now rather than after the songs.
            if ($wasCreated) {
                $this->requestAlbumSync($album);
            }
        }
        // resolveAlbum() keeps only the new files that are still current. Without an album, the
        // message imports no new song.
        if ($album !== null) {
            foreach ($newFiles as $file) {
                $targets[$file->absolutePath] = $album;
            }
        }
        $audioFiles = array_values(array_filter($audioFiles, static fn (DiscoveredFile $file): bool => isset($targets[$file->absolutePath])));

        // The albums the message touched, and the album-artist credit of each file whose tags were
        // read, by album and path.
        $albumIds = $album === null ? [] : [$album->getId()->toString() => $album->getId()];
        $albumArtists = [];
        $heldRemainder = null;
        foreach (array_chunk($audioFiles, self::BATCH_SIZE) as $batch) {
            $songs = [];
            foreach ($this->currentFiles($libraryId, $batch) as $file) {
                try {
                    $target = $targets[$file->absolutePath];
                    $song = match (true) {
                        isset($retagged[$file->absolutePath]) => $this->readRetaggedSong($retagged[$file->absolutePath], $file),
                        $target instanceof Song => $this->readKnownSong($target, $file),
                        default => $this->readNewSong($target, $file),
                    };
                    if ($song !== null) {
                        $songs[] = $song;
                        $songAlbum = $song['song']->getAlbumId();
                        $albumIds[$songAlbum->toString()] = $songAlbum;
                        $albumArtists[$songAlbum->toString()][$file->absolutePath] = $song['albumArtist'];
                    }
                } catch (\Throwable $e) {
                    $failures[] = $this->fileFailed($file, $e);
                }
            }
            if ($songs === []) {
                continue;
            }

            $written = $this->writeSongs($libraryId, $songs, $failures);
            if ($written === null) {
                // A delete with files claimed the library since the import began. The songs
                // committed so far stay, and the requeued import carries the other files only.
                $heldRemainder = new FilesDiscovered(
                    $libraryId,
                    $message->libraryType,
                    $message->directory,
                    array_values(array_filter($audioFiles, static fn (DiscoveredFile $file): bool => !isset($committedPaths[$file->absolutePath]))),
                );
                break;
            }

            foreach ($written as $song) {
                $committedPaths[$song['song']->getPath()] = true;
                // A known song is fetched for once it gained a primary credit or a length.
                $fetchable = !$song['known'] || ($song['repaired'] ?? false);
                if ($fetchable && $song['song']->getLyrics() === null) {
                    $songsAwaitingLyrics[] = $song['song']->getId();
                }
            }
            // A retry finds these songs credited and measured already and requests no lyrics for
            // them, so request their lyrics now, before any failed file is reported.
            $this->requestLyricsFetch($songsAwaitingLyrics);
        }

        // A message may carry only part of an album, such as one disc, so each credit is decided
        // from the songs committed so far.
        foreach ($albumIds as $key => $albumId) {
            $this->creditAlbum($albumId, $albumArtists[$key] ?? []);
        }

        if ($heldRemainder !== null) {
            $this->requeueWhileHeld($heldRemainder);

            return;
        }

        if ($failures !== []) {
            throw new RuntimeException($this->buildFailureMessage($failures));
        }

        // A consumer can run immediately after dispatch. Commit songs first, and
        // retry cover dispatch even when an earlier delivery created the album.
        foreach ($albumIds as $albumId) {
            $touched = $album !== null && $album->getId()->equals($albumId) ? $album : $this->albumService->findByUuid($albumId);
            if ($touched !== null && $touched->getCoverImageId() === null) {
                $this->dispatchCoverExtraction($touched);
            }
        }
    }

    /**
     * The stored song whose content each file carries, by path: one with the file's hash, whatever
     * album the song is in now. A file whose hash no song holds is absent, to become a new song.
     *
     * @param list<DiscoveredFile> $files
     *
     * @return array<string, Song>
     */
    private function knownSongs(array $files): array
    {
        $known = [];
        foreach ($files as $file) {
            $song = $this->songService->findByHash($file->hash);
            if ($song !== null) {
                $known[$file->absolutePath] = $song;
            }
        }

        return $known;
    }

    /**
     * The stored song at the path of each file whose content no song holds, by path. Such a file was
     * retagged, or its content changed otherwise, and updates that song. A song whose file was marked
     * unavailable counts as well: availability is decided by path, so a second song at the path
     * would count as available too.
     *
     * @param list<DiscoveredFile> $files files whose hash no song holds
     *
     * @return array<string, Song>
     */
    private function retaggedSongs(Uuid $libraryId, array $files): array
    {
        if ($files === []) {
            return [];
        }

        return $this->songService->findByPathsInLibrary($libraryId, array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $files));
    }

    /**
     * Reads a known song's file outside any transaction, so the song can be repaired from it: its tags
     * and its audio. A file in another directory than the song's file is left alone. A probe that fails
     * is logged and leaves the song's length and technical fields as they are.
     *
     * @return PreparedSong|null
     */
    private function readKnownSong(Song $stored, DiscoveredFile $file): ?array
    {
        $renamed = $stored->getPath() !== $file->absolutePath;
        if ($renamed && dirname($stored->getPath()) !== dirname($file->absolutePath)) {
            return null;
        }

        $metadata = $this->metadataReader->readMetadata($file->absolutePath);
        $song = [
            'file' => $file,
            'song' => $stored,
            'credit' => $metadata === null ? null : $this->taggedCredit($metadata->getArtists(), $metadata->getMbArtistIds()),
            'albumArtist' => $metadata === null ? null : $this->taggedCredit($metadata->getAlbumArtists(), $metadata->getMbAlbumArtistIds()),
            'genres' => [],
            'known' => true,
            'probe' => $this->probeKnownFile($file),
        ];
        if ($renamed) {
            $song['previousPath'] = $stored->getPath();
        }

        return $song;
    }

    private function probeKnownFile(DiscoveredFile $file): ?AudioProbeResult
    {
        try {
            return $this->audioProbe->probeAudio($file->absolutePath);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not probe a known file; its song keeps its stored length.', [
                'path' => $file->absolutePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reads a retagged file outside any transaction, so the song stored at its path can be updated
     * from it: its tag fields, the credit its tags give, and its audio. A file without readable
     * metadata is left alone, as a new file is. A probe that fails fails the file and leaves the song
     * as it is, since its hash would otherwise change while its technical fields still describe the
     * old content.
     *
     * @return PreparedSong|null
     */
    private function readRetaggedSong(Song $stored, DiscoveredFile $file): ?array
    {
        $tags = $this->readTags($file);
        if ($tags === null) {
            return null;
        }

        return [
            'file' => $file,
            'song' => $stored,
            'credit' => $tags['credit'],
            'albumArtist' => $tags['albumArtist'],
            'genres' => [],
            'known' => true,
            'retag' => ['fields' => $tags['fields'], 'probe' => $this->audioProbe->probeAudio($file->absolutePath)],
        ];
    }

    /** @return PreparedSong|null a new song, with the album-artist credit its tags give */
    private function readNewSong(Album $album, DiscoveredFile $file): ?array
    {
        $tags = $this->readTags($file);
        if ($tags === null) {
            return null;
        }

        $fields = $tags['fields'];
        $probe = $this->audioProbe->probeAudio($file->absolutePath);
        $song = Song::create(
            album: $album->getId(),
            title: $fields['title'],
            path: $file->absolutePath,
            size: $file->size,
            mimeType: $this->detectMimeType($file->extension),
            length: $probe->length(),
            lyrics: $this->findLyricsFile($file->absolutePath),
            track: $fields['track'],
            disc: $fields['disc'],
            year: $fields['year'],
            comment: $fields['comment'],
            hash: $file->hash,
            bitrate: $probe->bitrate,
            sampleRate: $probe->sampleRate,
            channels: $probe->channels,
            codec: $probe->codec,
            containerFormat: $probe->containerFormat,
            streamIndex: $probe->streamIndex,
        );

        return [
            'file' => $file,
            'song' => $song,
            'credit' => $tags['credit'],
            'albumArtist' => $tags['albumArtist'],
            'genres' => $tags['genres'],
            'known' => false,
        ];
    }

    /**
     * What a file's tags give its song, or null when the file has no readable metadata: the song
     * fields, with the title falling back to the file name and the track number to the one the file
     * name starts with; the song's and the album's tagged credits; and the genres.
     *
     * @return FileTags|null
     */
    private function readTags(DiscoveredFile $file): ?array
    {
        $metadata = $this->metadataReader->readMetadata($file->absolutePath);
        if ($metadata === null) {
            $this->logger->warning('No metadata for file', ['path' => $file->absolutePath]);

            return null;
        }

        $title = $metadata->getTitle() ?? '';

        return [
            'fields' => [
                'title' => trim($title) === '' ? pathinfo($file->absolutePath, PATHINFO_FILENAME) : $title,
                'track' => $metadata->getTrackNumber() ?? $this->extractTrackNumberFromFilename($file->absolutePath),
                'disc' => $metadata->getDiscNumber(),
                'year' => $metadata->getYear(),
                'comment' => $metadata->getComment(),
            ],
            'credit' => $this->taggedCredit($metadata->getArtists(), $metadata->getMbArtistIds()),
            'albumArtist' => $this->taggedCredit($metadata->getAlbumArtists(), $metadata->getMbAlbumArtistIds()),
            'genres' => array_values(array_filter(
                array_map(trim(...), $metadata->getGenre()),
                static fn (string $genre): bool => $genre !== '',
            )),
        ];
    }

    /**
     * The credit a file's artist values give its song, or its album-artist values give its album:
     * the first value names the artist, and the credited name joins every value with "; ". A single
     * value such as "Artist A, Artist B" stays whole. Null when the file names no artist.
     *
     * @param list<string> $artists        every artist value, in tag order
     * @param list<string> $musicbrainzIds every MusicBrainz ID the file gives for those artists
     *
     * @return TaggedCredit|null
     */
    private function taggedCredit(array $artists, array $musicbrainzIds): ?array
    {
        $names = [];
        foreach ($artists as $value) {
            $name = str_replace("\0", '', $value) |> trim(...);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return null;
        }

        return [
            'identity' => new ArtistIdentity($names[0], $musicbrainzIds),
            'creditedName' => implode('; ', $names),
        ];
    }

    /**
     * Gives the album an artist credit when it has none: from the first album-artist tag among the
     * album's files in the message, in path order, else among the files of the album's stored songs,
     * else to the artist every song of the album is credited to. The album service decides under a
     * lock on the album, so the tags are read before it is called.
     *
     * @param array<string, TaggedCredit|null> $albumArtists the album-artist credit of each of the album's message files whose tags were read, by path
     */
    private function creditAlbum(Uuid $albumId, array $albumArtists): void
    {
        if ($this->albumService->getArtistNamesForAlbum($albumId) !== []) {
            return;
        }

        ksort($albumArtists, SORT_STRING);
        $albumArtist = array_find($albumArtists, static fn (?array $credit): bool => $credit !== null)
            ?? $this->storedAlbumArtist($albumId, $albumArtists);
        $this->albumService->creditArtistIfUncredited($albumId, $albumArtist['identity'] ?? null, $albumArtist['creditedName'] ?? null);
    }

    /**
     * The album-artist credit of the first file, in path order, among the album's stored songs that
     * carries one. Files whose tags the message has read already are skipped.
     *
     * @param array<string, mixed> $read the paths whose tags were read already, as keys
     *
     * @return TaggedCredit|null
     */
    private function storedAlbumArtist(Uuid $albumId, array $read): ?array
    {
        $paths = array_map(static fn (Song $song): string => $song->getPath(), $this->songService->findByAlbumSortedByTrack($albumId));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (array_key_exists($path, $read)) {
                continue;
            }
            $metadata = $this->metadataReader->readMetadata($path);
            $credit = $metadata === null ? null : $this->taggedCredit($metadata->getAlbumArtists(), $metadata->getMbAlbumArtistIds());
            if ($credit !== null) {
                return $credit;
            }
        }

        return null;
    }

    /**
     * Writes the songs and their links in one transaction, unless a delete with files holds the
     * library. The transaction first reads each known song again and keeps the tagged credit only of a
     * song without any artist credit or with a retagged file, then resolves the artists and genres it
     * writes, then writes. A song that fails is added to $failures and the others are written; a
     * failed statement fails the transaction and with it the message.
     *
     * @param list<PreparedSong>                       $songs
     * @param list<array{path: string, error: string}> $failures
     *
     * @return list<PreparedSong>|null the songs the transaction committed; null when a delete holds the library and nothing was written
     */
    private function writeSongs(Uuid $libraryId, array $songs, array &$failures): ?array
    {
        $songFailures = [];
        $written = $this->transaction->run(function () use ($libraryId, $songs, &$songFailures): ?array {
            if ($this->mediaFiles->isHeldByDeleteForImport($libraryId)) {
                return null;
            }

            $hashes = $this->mediaFiles->lockAvailableHashesByPathsForImport($libraryId, array_map(
                static fn (array $song): string => $song['file']->absolutePath,
                $songs,
            ));
            $current = [];
            foreach ($songs as $song) {
                if (($hashes[$song['file']->absolutePath] ?? null) !== $song['file']->hash) {
                    continue;
                }
                try {
                    $prepared = match (true) {
                        isset($song['retag']) => $this->freshRetaggedSong($song),
                        $song['known'] => $this->freshKnownSong($libraryId, $song),
                        default => $song,
                    };
                    if ($prepared !== null) {
                        $current[] = $prepared;
                    }
                } catch (\Throwable $e) {
                    $songFailures[] = $this->fileFailed($song['file'], $e);
                }
            }
            $current = $this->withTagCreditsOfUncreditedSongsOnly($current);
            // Artist keys sort before genre keys, so the artists are locked first.
            $artistIds = $this->resolveArtists($current);
            $genreIds = $this->findOrCreateGenres($current);
            $written = [];
            foreach ($current as $song) {
                try {
                    if (isset($song['retag'])) {
                        $song['repaired'] = $this->retagSong($song, $song['retag'], $artistIds);
                    } elseif ($song['known']) {
                        $song['repaired'] = $this->repairSong($song, $artistIds);
                    } else {
                        $this->writeSong($song['song'], $song['credit'], $artistIds, array_map(static fn (string $name): Uuid => $genreIds[$name], $song['genres']));
                    }
                    $written[] = $song;
                } catch (\Throwable $e) {
                    $songFailures[] = $this->fileFailed($song['file'], $e);
                }
            }

            $this->songService->flush();
            $this->genreService->flush();

            return $written;
        });
        array_push($failures, ...$songFailures);

        return $written;
    }

    /**
     * The known song as the database holds it now, or null when it changed since its file was read.
     * A renamed file moves its song only when the song is still in the same album at the old path,
     * the old path is missing and the new path lies inside the library root.
     *
     * @param PreparedSong $song
     *
     * @return PreparedSong|null
     */
    private function freshKnownSong(Uuid $libraryId, array $song): ?array
    {
        $fresh = $this->songService->findFreshByUuid($song['song']->getId());
        if ($fresh === null || $fresh->getHash() !== $song['file']->hash) {
            return null;
        }
        $previousPath = $song['previousPath'] ?? null;
        $unchanged = $previousPath === null
            ? $fresh->getPath() === $song['file']->absolutePath
            : $fresh->getPath() === $previousPath && $fresh->getAlbumId()->equals($song['song']->getAlbumId())
                && $this->canMoveSource($libraryId, $previousPath, $song['file']);
        if (!$unchanged) {
            return null;
        }
        $song['song'] = $fresh;

        return $song;
    }

    /**
     * The song stored at a retagged file's path as the database holds it now, or null when it changed
     * since the file was read: it moved to another path or took other content.
     *
     * @param PreparedSong $song
     *
     * @return PreparedSong|null
     */
    private function freshRetaggedSong(array $song): ?array
    {
        $fresh = $this->songService->findFreshByUuid($song['song']->getId());
        if ($fresh === null || $fresh->getPath() !== $song['file']->absolutePath || $fresh->getHash() !== $song['song']->getHash()) {
            return null;
        }
        $song['song'] = $fresh;

        return $song;
    }

    private function canMoveSource(Uuid $libraryId, string $previousPath, DiscoveredFile $file): bool
    {
        $inspection = $this->mediaFiles->inspect($libraryId, [$previousPath, $file->absolutePath]);
        if (!$inspection->rootAvailable) {
            return false;
        }

        $verdicts = [];
        foreach ($inspection->files as $check) {
            $verdicts[$check->path] = $check->verdict;
        }

        return ($verdicts[$previousPath] ?? null) === LibraryMediaFileVerdict::Missing
            && in_array($verdicts[$file->absolutePath] ?? null, [LibraryMediaFileVerdict::Deletable, LibraryMediaFileVerdict::DirectoryNotWritable], true);
    }

    /**
     * Drops the tagged credit of each known song that has an artist credit of any role, so a rescan
     * neither adds to nor replaces a song's credits, and finds or creates no artist for such a song.
     * A retagged song keeps its tagged credit, which replaces its credits.
     *
     * @param list<PreparedSong> $songs
     *
     * @return list<PreparedSong>
     */
    private function withTagCreditsOfUncreditedSongsOnly(array $songs): array
    {
        $known = array_filter($songs, static fn (array $song): bool => $song['known'] && !isset($song['retag']) && $song['credit'] !== null);
        if ($known === []) {
            return $songs;
        }

        $credited = array_flip($this->songService->findCreditedSongIds(array_values(array_map(
            static fn (array $song): Uuid => $song['song']->getId(),
            $known,
        ))));
        foreach (array_keys($known) as $index) {
            if (isset($credited[$songs[$index]['song']->getId()->toString()])) {
                $songs[$index]['credit'] = null;
            }
        }

        return $songs;
    }

    /**
     * Repairs a known song from its file: moves it to the file's path when the file was renamed,
     * takes its length from the audio, fills technical fields it lacks, and credits its tagged
     * artist when it kept its tagged credit. The song is written only when something changed.
     *
     * @param PreparedSong        $song
     * @param array<string, Uuid> $artistIds the artist of each identity, by its key
     *
     * @return bool whether the song gained a primary credit or a length
     */
    private function repairSong(array $song, array $artistIds): bool
    {
        $stored = $song['song'];
        $changed = isset($song['previousPath']);
        if ($changed) {
            $stored->updateSourcePath($song['file']->absolutePath);
        }
        $gainedLength = false;
        $probe = $song['probe'] ?? null;
        if ($probe !== null) {
            if ($stored->getContainerFormat() === null || $stored->getStreamIndex() === null || $stored->getCodec() === null) {
                $this->applyAudioProbe($stored, $probe);
                $changed = true;
            }
            $length = $probe->length();
            if ($stored->getLength() !== $length) {
                $stored->updateLength($length);
                $changed = true;
                $gainedLength = $length !== null;
            }
        }
        if ($changed) {
            $this->songService->persist($stored);
        }

        if ($song['credit'] === null) {
            return $gainedLength;
        }
        $artistId = $artistIds[$song['credit']['identity']->key()];
        $this->songService->addArtistCredit($stored->getId(), $artistId, ArtistRole::Primary->value, $song['credit']['creditedName']);

        return true;
    }

    /**
     * Updates the song stored at a retagged file's path from the file. The song takes the file's hash,
     * size and technical fields, the length of its audio and its unlocked tag fields, and the credit
     * its tags give replaces every credit it held, hand-made ones too; tags that name no artist leave
     * it without credits. The song keeps its ID, album, lyrics and genres.
     *
     * @param PreparedSong        $song
     * @param Retag               $retag
     * @param array<string, Uuid> $artistIds the artist of each identity, by its key
     *
     * @return bool whether the song was given a primary credit or a length
     */
    private function retagSong(array $song, array $retag, array $artistIds): bool
    {
        $stored = $song['song'];
        $fields = $retag['fields'];
        $stored->updateFromTags(title: $fields['title'], track: $fields['track'], disc: $fields['disc'], year: $fields['year'], comment: $fields['comment']);
        $stored->updateAudioMetadata(size: $song['file']->size, hash: $song['file']->hash);
        $this->applyAudioProbe($stored, $retag['probe']);
        $length = $retag['probe']->length();
        $gainedLength = $length !== null && $stored->getLength() !== $length;
        $stored->updateLength($length);
        $this->songService->persist($stored);

        $this->songService->removeArtistCredits($stored->getId());
        if ($song['credit'] === null) {
            return $gainedLength;
        }
        $artistId = $artistIds[$song['credit']['identity']->key()];
        $this->songService->addArtistCredit($stored->getId(), $artistId, ArtistRole::Primary->value, $song['credit']['creditedName']);

        return true;
    }

    private function applyAudioProbe(Song $song, AudioProbeResult $probe): void
    {
        $song->updateAudioMetadata(bitrate: $probe->bitrate, sampleRate: $probe->sampleRate,
            channels: $probe->channels, codec: $probe->codec,
            containerFormat: $probe->containerFormat, streamIndex: $probe->streamIndex);
    }

    /**
     * Finds or creates the credited artists of the batch's songs in one call that locks them all,
     * before the batch writes any song.
     *
     * @param list<PreparedSong> $songs
     *
     * @return array<string, Uuid> the artist of each identity, by its key
     */
    private function resolveArtists(array $songs): array
    {
        $identities = [];
        foreach ($songs as $song) {
            if ($song['credit'] !== null) {
                $identities[] = $song['credit']['identity'];
            }
        }

        return $identities === [] ? [] : $this->artistIdentities->resolve($identities);
    }

    /**
     * Finds or creates the genres of the batch's songs in one call that locks them all, before the
     * batch writes any song.
     *
     * @param list<PreparedSong> $songs
     *
     * @return array<string, Uuid> the genre of each tagged name
     */
    private function findOrCreateGenres(array $songs): array
    {
        $names = [];
        foreach ($songs as $song) {
            array_push($names, ...$song['genres']);
        }

        return $names === [] ? [] : $this->genreService->findOrCreateForImport(array_values(array_unique($names)));
    }

    /**
     * @param TaggedCredit|null   $credit
     * @param array<string, Uuid> $artistIds the artist of each identity, by its key
     * @param list<Uuid>          $genreIds
     */
    private function writeSong(Song $song, ?array $credit, array $artistIds, array $genreIds): void
    {
        $this->songService->persist($song);

        if ($credit !== null) {
            $artistId = $artistIds[$credit['identity']->key()];
            $this->songService->addArtistCredit($song->getId(), $artistId, ArtistRole::Primary->value, $credit['creditedName']);
        }

        foreach ($genreIds as $genreId) {
            $this->genreService->addSongToGenre($genreId, $song->getId());
        }
    }

    /** @return array{path: string, error: string} */
    private function fileFailed(DiscoveredFile $file, \Throwable $e): array
    {
        $this->logger->warning('Failed to process file', [
            'path' => $file->absolutePath,
            'error' => $e->getMessage(),
        ]);

        return ['path' => $file->absolutePath, 'error' => $e->getMessage()];
    }

    /** @param list<DiscoveredFile> $files the message's files that still exist */
    private function processMovieFiles(FilesDiscovered $message, array $files): void
    {
        $failures = [];
        $committedPaths = [];
        $videoFiles = array_values(array_filter($files, static fn (DiscoveredFile $file): bool => $file->isVideo()));
        foreach (array_chunk($videoFiles, self::BATCH_SIZE) as $batch) {
            $prepared = [];
            foreach ($this->currentFiles($message->libraryId, $batch) as $file) {
                try {
                    $video = $this->videoRepository->findByHash($file->hash);
                    $isNew = $video === null;
                    $probe = $video === null || $this->videoNeedsProbe($video)
                        ? $this->ffprobeAdapter->probeVideo($file->absolutePath) : null;
                    if ($video === null) {
                        $video = Video::create(
                            path: $file->absolutePath,
                            hash: $file->hash,
                            duration: (int) $probe->duration,
                            height: $probe->height,
                            width: $probe->width,
                            videoBitrate: $probe->videoBitrate,
                            framerate: (int) $probe->framerate,
                        );
                        $video->updateProbe($probe->jsonSerialize());
                    }
                    $prepared[] = ['file' => $file, 'video' => $video, 'isNew' => $isNew, 'probe' => $probe];
                } catch (\Throwable $error) {
                    $failures[] = $this->fileFailed($file, $error);
                }
            }
            if ($prepared === []) {
                continue;
            }
            $written = $this->transaction->run(function () use ($message, $prepared, &$failures): ?array {
                if ($this->mediaFiles->isHeldByDeleteForImport($message->libraryId)) {
                    return null;
                }
                $hashes = $this->mediaFiles->lockAvailableHashesByPathsForImport($message->libraryId, array_map(
                    static fn (array $entry): string => $entry['file']->absolutePath,
                    $prepared,
                ));
                $current = array_filter($prepared, static fn (array $entry): bool => ($hashes[$entry['file']->absolutePath] ?? null) === $entry['file']->hash);
                if ($current === []) {
                    return [];
                }
                $movie = null;
                $written = [];
                foreach ($current as $entry) {
                    try {
                        $video = $entry['video'];
                        if ($entry['isNew']) {
                            $existing = $this->videoRepository->findByHash($entry['file']->hash);
                            if ($existing !== null) {
                                $video = $existing;
                            } else {
                                $this->videoRepository->save($video);
                            }
                        }
                        if (!$entry['isNew'] && $entry['probe'] !== null) {
                            $video = $this->videoRepository->findFreshByUuid($video->getId());
                            if ($video === null || $video->getHash() !== $entry['file']->hash
                                || $video->getPath() !== $entry['file']->absolutePath) {
                                continue;
                            }
                            $video->updateProbe($entry['probe']->jsonSerialize());
                            $this->videoRepository->save($video);
                        }
                        $movie ??= $this->resolveMovie($message->libraryId, basename($message->directory));
                        if (!in_array($video->getId()->toString(), $movie->getVideoIds(), true)) {
                            $movie->addVideo($video->getId());
                            $this->movieService->persist($movie);
                        }
                        $written[] = $entry['file']->absolutePath;
                    } catch (\Throwable $error) {
                        $failures[] = $this->fileFailed($entry['file'], $error);
                    }
                }
                $this->movieService->flush();

                return $written;
            });
            if ($written === null) {
                $this->requeueWhileHeld(new FilesDiscovered($message->libraryId, $message->libraryType, $message->directory,
                    array_values(array_filter($files, static fn (DiscoveredFile $file): bool => !isset($committedPaths[$file->absolutePath]))),
                ));

                return;
            }
            foreach ($written as $path) {
                $committedPaths[$path] = true;
            }
        }
        if ($failures !== []) {
            throw new RuntimeException($this->buildFailureMessage($failures));
        }
    }

    private function videoNeedsProbe(Video $video): bool
    {
        $probe = $video->getProbe();
        if (!isset($probe['containerFormat'], $probe['videoStreamIndex'])) {
            return true;
        }
        foreach ($probe['audioStreams'] ?? [] as $stream) {
            if (!isset($stream['streamIndex'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<DiscoveredFile> $files
     * @return array{Album|null, bool}|null Null means a delete holds the library.
     */
    private function resolveAlbum(Uuid $libraryId, string $directory, array &$files): ?array
    {
        $candidates = array_values(array_filter($files, static fn (DiscoveredFile $file): bool => $file->isAudio()));
        while ($candidates !== []) {
            $anchor = $candidates[0];
            $metadata = null;
            foreach ($candidates as $candidate) {
                $metadata = $this->metadataReader->readMetadata($candidate->absolutePath);
                if ($metadata !== null) {
                    $anchor = $candidate;
                    break;
                }
            }
            $title = $metadata?->getAlbum() ?? basename($directory);
            if (trim($title) === '' || trim($title) === '.') {
                return [null, false];
            }
            $current = [];
            $resolved = $this->transaction->run(function () use ($libraryId, $candidates, $anchor, $metadata, $title, &$current): ?array {
                if ($this->mediaFiles->isHeldByDeleteForImport($libraryId)) {
                    return null;
                }
                $hashes = $this->mediaFiles->lockAvailableHashesByPathsForImport($libraryId, array_map(
                    static fn (DiscoveredFile $file): string => $file->absolutePath,
                    $candidates,
                ));
                $current = array_values(array_filter($candidates, static fn (DiscoveredFile $file): bool => ($hashes[$file->absolutePath] ?? null) === $file->hash));
                if (($hashes[$anchor->absolutePath] ?? null) !== $anchor->hash) {
                    return [null, false];
                }
                $album = $this->albumService->findByTitleAndLibrary($title, $libraryId);
                if ($album !== null) {
                    return [$album, false];
                }
                $album = Album::create($libraryId, $title, AlbumType::Studio->value, year: $metadata?->getYear());
                $this->albumService->persist($album);
                $this->albumService->flush();

                return [$album, true];
            });
            if ($resolved === null || $resolved[0] !== null) {
                if ($resolved !== null) {
                    $files = $current;
                }

                return $resolved;
            }
            // An ineligible anchor is absent from this next, strictly smaller candidate set.
            $candidates = $current;
        }

        return [null, false];
    }

    private function resolveMovie(Uuid $libraryId, string $title): Movie
    {
        $existing = $this->movieService->findByTitleAndLibrary($title, $libraryId);
        if ($existing !== null) {
            return $existing;
        }

        $movie = Movie::create(libraryId: $libraryId, title: $title);
        $this->movieService->persist($movie);

        return $movie;
    }

    /**
     * Puts the message, or what is left of it, back on its queue with a delay. The delete renews
     * its claim while it runs and the claim lapses with its lease when the delete dies, so the
     * import runs in the end.
     */
    private function requeueWhileHeld(FilesDiscovered $message): void
    {
        $this->logger->info('A delete with files holds library {library_id}; the import of {directory} waits for it.', [
            'library_id' => $message->libraryId->toString(),
            'directory' => $message->directory,
        ]);

        $this->messageBus->dispatch($message, [
            new TransportNamesStamp(['async']),
            new DelayStamp(self::HELD_LIBRARY_RETRY_DELAY_MS),
        ]);
    }

    /**
     * The message's files that still exist. The others retain their file index rows as
     * unavailable, so the next incremental scan imports a file that comes back at its path.
     *
     * @return list<DiscoveredFile>
     */
    private function presentFiles(FilesDiscovered $message): array
    {
        $files = $this->currentFiles($message->libraryId, array_values($message->files));
        if ($files === []) {
            return [];
        }
        $missing = $this->mediaFiles->markMissingUnavailable(
            $message->libraryId,
            array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $files),
        );
        if ($missing === []) {
            return $files;
        }

        $this->logger->info('Skipping {count} file(s) deleted since the scan found them; a scan imports any that come back.', [
            'count' => count($missing),
            'paths' => $missing,
        ]);

        $missingByPath = array_flip($missing);

        return array_values(array_filter($files, static fn (DiscoveredFile $file): bool => !isset($missingByPath[$file->absolutePath])));
    }

    /** @param list<DiscoveredFile> $files
     * @return list<DiscoveredFile>
     */
    private function currentFiles(Uuid $libraryId, array $files): array
    {
        $hashes = $this->mediaFiles->findAvailableHashesByPaths($libraryId, array_map(
            static fn (DiscoveredFile $file): string => $file->absolutePath,
            $files,
        ));

        return array_values(array_filter($files, static fn (DiscoveredFile $file): bool => ($hashes[$file->absolutePath] ?? null) === $file->hash));
    }

    private function detectMimeType(string $extension): string
    {
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'opus' => 'audio/opus',
            'wma' => 'audio/x-ms-wma',
            default => 'application/octet-stream',
        };
    }

    private function extractTrackNumberFromFilename(string $path): ?int
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        if (preg_match('/^(\d+)\s*[-.\s]\s*(.+)$/', $filename, $matches)) {
            $trackNumber = (int) $matches[1];
            if ($trackNumber >= 1 && $trackNumber <= 99) {
                return $trackNumber;
            }
        }
        return null;
    }

    private function findLyricsFile(string $audioPath): ?string
    {
        $lyricPath = pathinfo($audioPath, PATHINFO_DIRNAME)
            . DIRECTORY_SEPARATOR
            . pathinfo($audioPath, PATHINFO_FILENAME)
            . '.lrc';

        if (file_exists($lyricPath) && is_readable($lyricPath)) {
            return file_get_contents($lyricPath);
        }
        return null;
    }

    /**
     * @param array<int, array{path: string, error: string}> $failures
     */
    private function buildFailureMessage(array $failures): string
    {
        $details = array_map(
            static fn (array $failure): string => sprintf('%s: %s', $failure['path'], $failure['error']),
            $failures,
        );

        return sprintf('Failed to process %d file(s): %s', count($failures), implode('; ', $details));
    }

    /**
     * Requests a metadata sync for an album that resolveAlbum() has just committed.
     *
     * The request is best-effort: a failure must not fail the ingest, whose retry
     * would find the album and request nothing. A library metadata sync recovers it.
     */
    private function requestAlbumSync(Album $album): void
    {
        try {
            $this->albumMetadataSync->requestSync($album->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Could not request a metadata sync for new album {album_id}; a library metadata sync from the admin panel picks it up.', [
                'album_id' => $album->getId()->toString(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Requests lyrics for songs that a flush has just committed, then forgets them.
     *
     * The request is best-effort: a failure must not fail the ingest, whose retry
     * finds these songs committed and complete and requests nothing for them. Failed
     * songs stay pending, so the next flush asks again; after the last flush the bulk
     * lyrics fetch recovers them.
     *
     * @param list<Uuid> $songIds
     */
    private function requestLyricsFetch(array &$songIds): void
    {
        if ($songIds === []) {
            return;
        }

        try {
            $this->lyricsFetch->requestFetch(...$songIds);
        } catch (\Throwable $e) {
            $this->logger->error('Could not request lyrics for {count} song(s); they stay pending until the next flush.', [
                'count' => count($songIds),
                'song_ids' => array_map(static fn (Uuid $id): string => $id->toString(), $songIds),
                'exception' => $e,
            ]);

            return;
        }

        $songIds = [];
    }

    private function dispatchCoverExtraction(Album $album): void
    {
        $this->messageBus->dispatch(
            new \App\Metadata\Application\Command\ExtractAlbumCoverCommand($album->getId()),
        );
    }
}
