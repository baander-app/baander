<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Song;

use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\CommandHandler\Song\UpdateSongHandler;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use PHPUnit\Framework\TestCase;

/**
 * The song edit over an in-memory port that answers the read before the transaction and the read
 * under the song's row lock from two songs: what the edit saw when it started, and what the
 * database holds once every writer that held the row has committed.
 */
final class UpdateSongHandlerTest extends TestCase
{
    /** The song as the edit reads it before its transaction. */
    private Song $read;
    /** The song as the database holds it once its row is locked; null when it is gone by then. */
    private ?Song $underLock;
    /** @var list<string> the lock, the read under it and the save, in the order the port was asked for them */
    private array $steps = [];
    /** @var list<Song> */
    private array $saved = [];
    private PassThroughTransaction $transaction;
    private SongPortInterface $songs;

    protected function setUp(): void
    {
        $this->read = Song::create(new Uuid(), 'Pour Me A Drink', '/music/pour-me-a-drink.flac', 1024, 'audio/flac');
        $this->underLock = Song::reconstitute(new SongState(...get_object_vars($this->read->getState())));
        $this->transaction = new PassThroughTransaction();

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?Song => $id->equals($this->read->getPublicId()) ? $this->read : null);
        $songs->method('lockInIdOrder')->willReturnCallback(function (array $ids): void {
            $this->step('song row ' . implode(', ', array_map(static fn (Uuid $id): string => $id->toString(), $ids)));
        });
        $songs->method('findFreshByUuid')->willReturnCallback(function (Uuid $id): ?Song {
            $this->step('read');

            return $id->equals($this->read->getId()) ? $this->underLock : null;
        });
        $songs->method('save')->willReturnCallback(function (Song $song): void {
            $this->step('save');
            $this->saved[] = $song;
        });
        $this->songs = $songs;
    }

    /**
     * A match set the song's title and credits. Between the edit's first read and its lock, a hand
     * change dropped the credits mark and a match set the year. The edit of the title saves the song
     * as it is under the lock: the year and its mark stay, the credits mark stays dropped, and only
     * the mark of the replaced title goes.
     */
    public function testTheEditLocksTheSongsRowAndSavesTheSongAsItIsUnderTheLockWithoutTheReplacedFieldsMark(): void
    {
        $this->read->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Credits);
        $underLock = $this->underLock ?? self::fail('The song exists.');
        $underLock->updateMetadata(year: 2024);
        $underLock->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Year);

        $returned = (new UpdateSongHandler($this->songs, $this->transaction))(
            new UpdateSongCommand($this->read->getPublicId()->toString(), title: 'Pour Me A Drink (Live)'),
        );

        self::assertSame(1, $this->transaction->runs);
        self::assertSame(['song row ' . $this->read->getId()->toString(), 'read', 'save'], $this->steps);
        self::assertSame([$underLock], $this->saved);
        self::assertSame($underLock, $returned);
        self::assertSame(['Pour Me A Drink (Live)', 2024], [$underLock->getTitle(), $underLock->getYear()]);
        self::assertSame([MatchedSongField::Year], $underLock->getMatchedFields());
    }

    public function testASongGoneByTheTimeItsRowIsLockedIsNotFoundAndNothingIsSaved(): void
    {
        $this->underLock = null;
        $publicId = $this->read->getPublicId()->toString();

        try {
            (new UpdateSongHandler($this->songs, $this->transaction))(new UpdateSongCommand($publicId, title: 'Pour Me A Drink (Live)'));
            self::fail('The edit was accepted.');
        } catch (NotFoundException $notFound) {
            self::assertSame(sprintf('Song "%s" not found.', $publicId), $notFound->getMessage());
        }

        self::assertSame([], $this->saved);
    }

    /** Records a lock, a read under it or a save, which the edit may only ask for inside its transaction. */
    private function step(string $step): void
    {
        self::assertTrue($this->transaction->active, sprintf('"%s" was asked for outside the transaction.', $step));
        $this->steps[] = $step;
    }
}
