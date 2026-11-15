<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Catalog\Application\CommandHandler\Artist\AddArtistCreditHandler;
use App\Catalog\Application\CommandHandler\Artist\ChangeArtistCreditRoleHandler;
use App\Catalog\Application\CommandHandler\Artist\RemoveArtistCreditHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\ArtistState;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The credit use cases over in-memory ports. The artist port stores the roles of one artist per
 * target, as ArtistRepository does. Every port records the locks and writes it is asked for, each
 * of which must come inside the use case's transaction, and answers a read under the locks from
 * what the test says the database holds by then.
 */
final class ArtistCreditHandlersTest extends TestCase
{
    private const string MBID = '8c538f11-c141-4588-8ecb-931083524186';
    private const string BUSY_MESSAGE = 'Another change to the same artist, song or album ran at the same time. Make the change again.';

    private Artist $artist;
    /** The artist as the database holds it once the locks are taken; null when it is gone by then. */
    private ?Artist $artistUnderLock;
    private Uuid $song;
    private Uuid $album;
    /** @var array<string, list<string|null>> roles by "song|<uuid>" or "album|<uuid>" */
    private array $credits = [];
    /** @var list<string|null>|null what a read of the roles answers until the locks are taken; null for the stored roles */
    private ?array $rolesBeforeLocks = null;
    /** @var list<array{string, string|null, string}> calls to update*Role: target key, current role, new role */
    private array $changes = [];
    private ArtistPortInterface $artists;
    private SongPortInterface $songs;
    private AlbumPortInterface $albums;
    private ArtistMergePortInterface $merges;
    private PassThroughTransaction $transaction;
    private string $songPublicId;
    private string $albumPublicId;
    /** The song as the use case reads it before its transaction. */
    private Song $songModel;
    /** The song as the database holds it once its row is locked; null when it is gone by then. */
    private ?Song $songUnderLock;
    private bool $albumGoneUnderLock = false;
    /** @var list<Song> the songs saved */
    private array $savedSongs = [];
    /** @var list<string> the locks and writes the ports were asked for, in order */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->artist = Artist::create('Nina Simone');
        $this->artist->updateExternalIds(mbid: self::MBID);
        $this->artistUnderLock = $this->artist;
        $album = Album::create(new Uuid(), 'Album', 'album');
        $song = Song::create($album->getId(), 'Song', '/music/song.flac', 1, 'audio/flac');
        $this->song = $song->getId();
        $this->album = $album->getId();
        $this->songPublicId = $song->getPublicId()->toString();
        $this->albumPublicId = $album->getPublicId()->toString();
        $this->songModel = $song;
        $this->songUnderLock = $song;
        $this->transaction = new PassThroughTransaction();

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?Song => $id->equals($song->getPublicId()) ? $song : null);
        $songs->method('findFreshByUuid')->willReturnCallback(fn (Uuid $id): ?Song => $id->equals($song->getId()) ? $this->songUnderLock : null);
        $songs->method('lockImportIdentities')->willReturnCallback(function (array $artists, array $genres): void {
            self::assertSame([], $genres);
            $this->step('keys ' . implode(', ', array_map(
                static fn (ArtistIdentity $identity): string => $identity->name . '|' . $identity->musicbrainzId?->toString(),
                $artists,
            )));
        });
        $songs->method('lockInIdOrder')->willReturnCallback(fn (array $ids) => $this->step('song row ' . self::ids($ids)));
        $songs->method('save')->willReturnCallback(function (Song $saved): void {
            $this->step('save');
            $this->savedSongs[] = $saved;
        });
        $this->songs = $songs;

        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?Album => $id->equals($album->getPublicId()) ? $album : null);
        $albums->method('findFreshByUuid')->willReturnCallback(fn (Uuid $id): ?Album => $id->equals($album->getId()) && !$this->albumGoneUnderLock ? $album : null);
        $albums->method('lockInIdOrder')->willReturnCallback(fn (array $ids) => $this->step('album row ' . self::ids($ids)));
        $this->albums = $albums;

        $merges = $this->createStub(ArtistMergePortInterface::class);
        $merges->method('lock')->willReturnCallback(function (array $ids): array {
            $this->step('artist row ' . self::ids($ids));

            return $this->artistUnderLock === null ? [] : [$this->artist->getId()->toString() => true];
        });
        $this->merges = $merges;

        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Artist => $id->equals($this->artist->getPublicId()) ? $this->artist : null,
        );
        $artists->method('findFreshByUuid')->willReturnCallback(fn (Uuid $id): ?Artist => $id->equals($this->artist->getId()) ? $this->artistUnderLock : null);
        $artists->method('addSongToArtist')->willReturnCallback(fn (Uuid $artist, Uuid $song, string $role): bool => $this->add('song', $song, $role));
        $artists->method('addAlbumToArtist')->willReturnCallback(fn (Uuid $artist, Uuid $album, string $role): bool => $this->add('album', $album, $role));
        $artists->method('removeSongFromArtist')->willReturnCallback(fn (Uuid $artist, Uuid $song): bool => $this->remove('song', $song));
        $artists->method('removeAlbumFromArtist')->willReturnCallback(fn (Uuid $artist, Uuid $album): bool => $this->remove('album', $album));
        $artists->method('songCreditRoles')->willReturnCallback(fn (Uuid $artist, Uuid $song): array => $this->roles('song', $song, 'song row'));
        $artists->method('albumCreditRoles')->willReturnCallback(fn (Uuid $artist, Uuid $album): array => $this->roles('album', $album, 'artist row'));
        $artists->method('updateSongRole')->willReturnCallback(fn (Uuid $artist, Uuid $song, ?string $current, string $role): bool => $this->change('song', $song, $current, $role));
        $artists->method('updateAlbumRole')->willReturnCallback(fn (Uuid $artist, Uuid $album, ?string $current, string $role): bool => $this->change('album', $album, $current, $role));
        $this->artists = $artists;
    }

    public function testInternalSongUuidIsRejectedBeforeAnyMutation(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Invalid song ID format.');
        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->song->toString(), 'primary'));
    }

    public function testAddingACreditTwiceKeepsOneCredit(): void
    {
        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->songPublicId, 'primary'));
        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->songPublicId, 'primary'));

        self::assertSame(['primary'], $this->credits['song|' . $this->song->toString()]);
    }

    public function testAddingACreditForAnUnknownAlbumIsNotFound(): void
    {
        $unknown = (new PublicId())->toString();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Album "%s" not found.', $unknown));

        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Album, $unknown, 'primary'));
    }

    /** @return iterable<string, array{object, class-string<\Throwable>, string}> */
    public static function rejectedInput(): iterable
    {
        $song = (new PublicId())->toString();
        $artist = (new PublicId())->toString();

        yield 'unknown role' => [new AddArtistCreditCommand($artist, CreditTarget::Song, $song, 'singer'), InvalidInputException::class, 'Invalid role "singer". Valid roles: primary, featured,'];
        yield 'unknown current role' => [new ChangeArtistCreditRoleCommand($artist, CreditTarget::Song, $song, 'primary', 'singer'), InvalidInputException::class, 'Invalid role "singer".'];
        yield 'malformed song ID' => [new RemoveArtistCreditCommand($artist, CreditTarget::Song, 'not-a-uuid'), InvalidInputException::class, 'Invalid song ID format.'];
        yield 'malformed album ID' => [new AddArtistCreditCommand($artist, CreditTarget::Album, 'not-a-uuid', 'primary'), InvalidInputException::class, 'Invalid album ID format.'];
        yield 'malformed public ID' => [new AddArtistCreditCommand('not a public id', CreditTarget::Song, $song, 'primary'), InvalidInputException::class, 'Invalid public ID format.'];
        yield 'unknown artist' => [new RemoveArtistCreditCommand($artist, CreditTarget::Song, $song), NotFoundException::class, sprintf('Artist "%s" not found.', $artist)];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('rejectedInput')]
    public function testRejectedInputChangesNothing(object $command, string $exception, string $message): void
    {
        try {
            $this->handle($command);
            self::fail('The command was accepted.');
        } catch (\Throwable $failure) {
            self::assertInstanceOf($exception, $failure);
            self::assertStringStartsWith($message, $failure->getMessage());
        }

        self::assertSame([[], []], [$this->credits, $this->changes]);
    }

    public function testRemovingACreditTheArtistDoesNotHaveIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Artist "%s" has no credit on song "%s".', $this->publicId(), $this->songPublicId));

        $this->handle(new RemoveArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->songPublicId));
    }

    public function testRemovingACreditRemovesEveryRole(): void
    {
        $this->credits['album|' . $this->album->toString()] = ['featured', 'producer'];

        $this->handle(new RemoveArtistCreditCommand($this->publicId(), CreditTarget::Album, $this->albumPublicId));

        self::assertSame([], $this->credits);
    }

    public function testChangingTheOnlyCreditNeedsNoCurrentRole(): void
    {
        $this->credits['song|' . $this->song->toString()] = [null];

        $this->changeSong('featured');

        self::assertSame([['song|' . $this->song->toString(), null, 'featured']], $this->changes);
    }

    public function testChangingOneOfSeveralCreditsWithoutTheCurrentRoleIsInvalid(): void
    {
        $this->credits['song|' . $this->song->toString()] = ['featured', 'producer'];

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('(featured, producer); name the role to change.');

        try {
            $this->changeSong('primary');
        } finally {
            self::assertSame([], $this->changes);
        }
    }

    public function testChangingANamedCreditPassesItsRole(): void
    {
        $this->credits['album|' . $this->album->toString()] = ['featured', 'producer'];

        $this->handle(new ChangeArtistCreditRoleCommand($this->publicId(), CreditTarget::Album, $this->albumPublicId, 'producer', 'featured'));

        self::assertSame([['album|' . $this->album->toString(), 'featured', 'producer']], $this->changes);
    }

    public function testChangingACreditTheArtistDoesNotHaveIsNotFound(): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Artist "%s" has no "featured" credit on song "%s".', $this->publicId(), $this->songPublicId));

        $this->changeSong('producer', 'featured');
    }

    public function testChangingARoleWithoutAnyCreditIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('has no credit on song');

        $this->changeSong('producer');
    }

    /** @return iterable<string, array{'add'|'remove'|'role'}> one hand-made change of each kind */
    public static function creditChanges(): iterable
    {
        yield 'a new credit' => ['add'];
        yield 'a removed credit' => ['remove'];
        yield 'a changed role' => ['role'];
    }

    /** @return iterable<string, array{'add'|'remove'|'role', CreditTarget}> each kind of change to a song's and to an album's credits */
    public static function creditChangesOfEitherTarget(): iterable
    {
        foreach (self::creditChanges() as $name => [$change]) {
            foreach (CreditTarget::cases() as $target) {
                yield sprintf('%s on the %s', $name, $target->value) => [$change, $target];
            }
        }
    }

    /**
     * An album's row comes first, then the import keys of the artist's name and MusicBrainz ID, the
     * artist's row and a song's row, and only then the writes.
     *
     * @param 'add'|'remove'|'role' $change
     */
    #[DataProvider('creditChangesOfEitherTarget')]
    public function testAChangeTakesItsLocksInTheCatalogOrderInOneTransactionBeforeItWrites(string $change, CreditTarget $target): void
    {
        $this->credits[$target->value . '|' . $this->targetId($target)->toString()] = ['primary'];
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);

        $this->handle($this->command($change, $target));

        $keys = 'keys Nina Simone|' . self::MBID;
        $artistRow = 'artist row ' . $this->artist->getId()->toString();
        self::assertSame(1, $this->transaction->runs);
        self::assertSame(
            $target === CreditTarget::Song
                ? [$keys, $artistRow, 'song row ' . $this->song->toString(), 'credit', 'save']
                : ['album row ' . $this->album->toString(), $keys, $artistRow, 'credit'],
            $this->steps,
        );
    }

    /** @param 'add'|'remove'|'role' $change */
    #[DataProvider('creditChanges')]
    public function testAHandMadeChangeToASongsCreditsForgetsThatAMatchSetThem(string $change): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];
        $this->songModel->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Credits);

        $this->handle($this->command($change, CreditTarget::Song));

        self::assertSame([MatchedSongField::Title], $this->songModel->getMatchedFields());
        self::assertSame([$this->songModel], $this->savedSongs);
        self::assertSame(1, $this->transaction->runs, 'The credit and the dropped mark are written in one transaction.');
    }

    /**
     * A match set the song's credits after the use case read the song and before it held the song's
     * row. The mark is dropped on the song as it is under the lock, and that song is saved.
     *
     * @param 'add'|'remove'|'role' $change
     */
    #[DataProvider('creditChanges')]
    public function testAHandMadeChangeDropsTheMarkOfTheSongAsItIsUnderItsLock(string $change): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];
        $this->songUnderLock = Song::reconstitute(new SongState(...get_object_vars($this->songModel->getState())));
        $this->songUnderLock->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Credits);

        $this->handle($this->command($change, CreditTarget::Song));

        self::assertSame([MatchedSongField::Title], $this->songUnderLock->getMatchedFields());
        self::assertSame([$this->songUnderLock], $this->savedSongs);
    }

    public function testCreditingASongWithACreditItHoldsKeepsTheMatchedCredits(): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);

        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->songPublicId, 'primary'));

        self::assertSame([MatchedSongField::Credits], $this->songModel->getMatchedFields());
        self::assertSame([], $this->savedSongs);
    }

    /**
     * The artist took the credit after the use case started: before the locks the song did not
     * hold it, under them it does, so the add changes nothing a match set.
     */
    public function testAnAddDecidesUnderItsLocksWhetherTheSongHoldsTheCredit(): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];
        $this->rolesBeforeLocks = [];
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);

        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->songPublicId, 'primary'));

        self::assertSame([MatchedSongField::Credits], $this->songModel->getMatchedFields());
        self::assertSame([], $this->savedSongs);
    }

    /**
     * The artist's only credit changed from featured to producer after the use case started: the
     * role change that names no current role changes the producer credit it reads under its locks.
     */
    #[DataProvider('targets')]
    public function testARoleChangeDecidesOnTheRolesItReadsUnderItsLocks(CreditTarget $target): void
    {
        $key = $target->value . '|' . $this->targetId($target)->toString();
        $this->credits[$key] = ['producer'];
        $this->rolesBeforeLocks = ['featured'];

        $this->handle(new ChangeArtistCreditRoleCommand($this->publicId(), $target, $this->targetPublicId($target), 'composer'));

        self::assertSame([[$key, 'producer', 'composer']], $this->changes);
    }

    /** @return iterable<string, array{CreditTarget}> */
    public static function targets(): iterable
    {
        yield 'a song' => [CreditTarget::Song];
        yield 'an album' => [CreditTarget::Album];
    }

    public function testChangingAnAlbumsCreditsLeavesItsSongsMatchedCredits(): void
    {
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);

        $this->handle(new AddArtistCreditCommand($this->publicId(), CreditTarget::Album, $this->albumPublicId, 'primary'));

        self::assertSame([MatchedSongField::Credits], $this->songModel->getMatchedFields());
        self::assertSame([], $this->savedSongs);
    }

    /** @return iterable<string, array{'artist'|'song'|'album', CreditTarget}> what another writer removed before the locks were taken */
    public static function goneUnderTheLocks(): iterable
    {
        yield 'the artist' => ['artist', CreditTarget::Song];
        yield 'the song' => ['song', CreditTarget::Song];
        yield 'the album' => ['album', CreditTarget::Album];
    }

    /** @param 'artist'|'song'|'album' $gone */
    #[DataProvider('goneUnderTheLocks')]
    public function testAnArtistSongOrAlbumGoneUnderTheLocksIsNotFoundAndNothingIsWritten(string $gone, CreditTarget $target): void
    {
        $credits = [$target->value . '|' . $this->targetId($target)->toString() => ['primary']];
        $this->credits = $credits;
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);
        $message = match ($gone) {
            'artist' => sprintf('Artist "%s" not found.', $this->publicId()),
            'song' => sprintf('Song "%s" not found.', $this->songPublicId),
            'album' => sprintf('Album "%s" not found.', $this->albumPublicId),
        };
        match ($gone) {
            'artist' => $this->artistUnderLock = null,
            'song' => $this->songUnderLock = null,
            'album' => $this->albumGoneUnderLock = true,
        };

        foreach (self::creditChanges() as $name => [$change]) {
            try {
                $this->handle($this->command($change, $target));
                self::fail(sprintf('%s was accepted.', $name));
            } catch (NotFoundException $notFound) {
                self::assertSame($message, $notFound->getMessage(), $name);
            }
        }

        self::assertSame([$credits, [], []], [$this->credits, $this->changes, $this->savedSongs]);
    }

    /** @param 'add'|'remove'|'role' $change */
    #[DataProvider('creditChanges')]
    public function testAChangeTheDatabaseEndedToBreakADeadlockAnswersThatItShouldBeMadeAgain(string $change): void
    {
        $credits = ['song|' . $this->song->toString() => ['primary']];
        $this->credits = $credits;
        $deadlocked = new class implements TransactionPortInterface {
            public function run(callable $operation): mixed
            {
                throw new TransactionDeadlockException(new \RuntimeException('deadlock detected'));
            }
        };

        try {
            $this->handle($this->command($change, CreditTarget::Song), $deadlocked);
            self::fail('The change was accepted.');
        } catch (ConflictException $conflict) {
            self::assertSame(['reason' => 'credit_busy'], $conflict->details);
            self::assertSame(self::BUSY_MESSAGE, $conflict->getMessage());
            self::assertInstanceOf(TransactionDeadlockException::class, $conflict->getPrevious());
        }

        self::assertSame([$credits, [], []], [$this->credits, $this->changes, $this->savedSongs]);
    }

    /** @return iterable<string, array{array{name?: string, mbid?: string}}> what the artist holds once the locks are taken */
    public static function otherIdentities(): iterable
    {
        yield 'another name' => [['name' => 'Eunice Waymon']];
        yield 'another MusicBrainz ID' => [['mbid' => '4a1e2b2c-0f0f-4e5c-9d3a-2f6a8c1b7d90']];
    }

    /**
     * The keys taken cover the name and ID read before them. An artist that holds another name or
     * ID by the time its row is locked is written by no change: its new key was not taken.
     *
     * @param array{name?: string, mbid?: string} $identity
     */
    #[DataProvider('otherIdentities')]
    public function testAnArtistThatTookAnotherNameOrIdBeforeTheLocksAnswersThatTheChangeShouldBeMadeAgain(array $identity): void
    {
        $credits = ['song|' . $this->song->toString() => ['primary']];
        $this->credits = $credits;
        $this->songModel->recordMatchedFields(MatchedSongField::Credits);
        $this->artistUnderLock = Artist::reconstitute(new ArtistState(...array_replace(get_object_vars($this->artist->getState()), $identity)));

        foreach (self::creditChanges() as $name => [$change]) {
            try {
                $this->handle($this->command($change, CreditTarget::Song));
                self::fail(sprintf('%s was accepted.', $name));
            } catch (ConflictException $conflict) {
                self::assertSame(['reason' => 'credit_busy'], $conflict->details, $name);
                self::assertSame(self::BUSY_MESSAGE, $conflict->getMessage(), $name);
            }
        }

        self::assertSame([$credits, [], []], [$this->credits, $this->changes, $this->savedSongs]);
        self::assertSame([MatchedSongField::Credits], $this->songModel->getMatchedFields());
    }

    private function changeSong(string $role, ?string $current = null): void
    {
        $this->handle(new ChangeArtistCreditRoleCommand($this->publicId(), CreditTarget::Song, $this->songPublicId, $role, $current));
    }

    /** @param 'add'|'remove'|'role' $change */
    private function command(string $change, CreditTarget $target): object
    {
        return match ($change) {
            'add' => new AddArtistCreditCommand($this->publicId(), $target, $this->targetPublicId($target), 'featured'),
            'remove' => new RemoveArtistCreditCommand($this->publicId(), $target, $this->targetPublicId($target)),
            'role' => new ChangeArtistCreditRoleCommand($this->publicId(), $target, $this->targetPublicId($target), 'featured'),
        };
    }

    private function handle(object $command, ?TransactionPortInterface $transaction = null): void
    {
        $ports = [$this->artists, $this->songs, $this->albums, $this->merges, $transaction ?? $this->transaction];
        match (true) {
            $command instanceof AddArtistCreditCommand => (new AddArtistCreditHandler(...$ports))($command),
            $command instanceof RemoveArtistCreditCommand => (new RemoveArtistCreditHandler(...$ports))($command),
            $command instanceof ChangeArtistCreditRoleCommand => (new ChangeArtistCreditRoleHandler(...$ports))($command),
            default => throw new \LogicException('Unknown command.'),
        };
    }

    private function publicId(): string
    {
        return $this->artist->getPublicId()->toString();
    }

    private function targetId(CreditTarget $target): Uuid
    {
        return $target === CreditTarget::Song ? $this->song : $this->album;
    }

    private function targetPublicId(CreditTarget $target): string
    {
        return $target === CreditTarget::Song ? $this->songPublicId : $this->albumPublicId;
    }

    /** Records a lock or a write, which the use case may only ask for inside its transaction. */
    private function step(string $step): void
    {
        self::assertTrue($this->transaction->active, sprintf('"%s" was asked for outside the transaction.', $step));
        $this->steps[] = $step;
    }

    /** @param list<Uuid> $ids */
    private static function ids(array $ids): string
    {
        return implode(', ', array_map(static fn (Uuid $id): string => $id->toString(), $ids));
    }

    /**
     * @param string $lastLock the lock a change to this kind of target takes last
     *
     * @return list<string|null>
     */
    private function roles(string $kind, Uuid $target, string $lastLock): array
    {
        $locked = array_any($this->steps, static fn (string $step): bool => str_starts_with($step, $lastLock));

        return $locked || $this->rolesBeforeLocks === null ? $this->credits[$kind . '|' . $target->toString()] ?? [] : $this->rolesBeforeLocks;
    }

    private function add(string $kind, Uuid $target, string $role): bool
    {
        if (!$target->equals($kind === 'song' ? $this->song : $this->album)) {
            return false;
        }
        $this->step('credit');
        $key = $kind . '|' . $target->toString();
        $roles = $this->credits[$key] ?? [];
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
        }
        $this->credits[$key] = $roles;

        return true;
    }

    private function remove(string $kind, Uuid $target): bool
    {
        $key = $kind . '|' . $target->toString();
        if (!isset($this->credits[$key])) {
            return false;
        }
        $this->step('credit');
        unset($this->credits[$key]);

        return true;
    }

    private function change(string $kind, Uuid $target, ?string $current, string $role): bool
    {
        $this->step('credit');
        $this->changes[] = [$kind . '|' . $target->toString(), $current, $role];

        return true;
    }
}
