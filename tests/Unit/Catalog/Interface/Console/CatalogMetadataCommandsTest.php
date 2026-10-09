<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Album\UpdateAlbumCommand;
use App\Catalog\Application\Command\Artist\CreateArtistCommand;
use App\Catalog\Application\Command\Artist\UpdateArtistCommand;
use App\Catalog\Application\Command\Movie\UpdateMovieCommand;
use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\CommandHandler\Album\UpdateAlbumHandler;
use App\Catalog\Application\CommandHandler\Artist\CreateArtistHandler;
use App\Catalog\Application\CommandHandler\Artist\UpdateArtistHandler;
use App\Catalog\Application\CommandHandler\Movie\UpdateMovieHandler;
use App\Catalog\Application\CommandHandler\Song\UpdateSongHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Console\AlbumUpdateCommand;
use App\Catalog\Interface\Console\ArtistCreateCommand;
use App\Catalog\Interface\Console\ArtistUpdateCommand;
use App\Catalog\Interface\Console\MovieUpdateCommand;
use App\Catalog\Interface\Console\SongUpdateCommand;
use App\Catalog\Interface\Resource\AlbumResource;
use App\Catalog\Interface\Resource\ArtistResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class CatalogMetadataCommandsTest extends TestCase
{
    public function testAlbumUpdatePrintsTheResourceTheApiReturns(): void
    {
        $album = Album::create(new Uuid(), 'Abbey Road', 'album');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $albums->expects(self::once())->method('save')->with($album);

        $tester = new CommandTester(new AlbumUpdateCommand($this->support(albums: $albums)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'public-id' => $album->getPublicId()->toString(),
            '--year' => '1969',
            '--label' => 'Apple',
            '--lock' => ['barcode,country'],
            '--json' => true,
        ]));
        self::assertSame(AlbumResource::from($album), json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([1969, 'Apple'], [$album->getYear(), $album->getLabel()]);
        self::assertSame(['barcode', 'country'], $album->getLockedFields());
    }

    public function testAlbumUpdateMayUnlockAFieldAndChangeIt(): void
    {
        $album = Album::create(new Uuid(), 'Abbey Road', 'album');
        $album->lockField('title');
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);

        $tester = new CommandTester(new AlbumUpdateCommand($this->support(albums: $albums)));

        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => $album->getPublicId()->toString(), '--unlock' => ['title'], '--title' => 'Let It Be']));
        self::assertStringContainsString('Album "Let It Be"', $tester->getDisplay());
        self::assertSame([], $album->getLockedFields());
    }

    public function testAnUnknownLockedFieldOrALockedEditIsInvalidAndSavesNothing(): void
    {
        $album = Album::create(new Uuid(), 'Abbey Road', 'album');
        $album->lockField('label');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $albums->expects(self::never())->method('save');
        $command = new AlbumUpdateCommand($this->support(albums: $albums));

        $unknown = new CommandTester($command);
        self::assertSame(Command::INVALID, $unknown->execute(['public-id' => $album->getPublicId()->toString(), '--lock' => ['colour']]));
        self::assertStringContainsString('Cannot lock unknown field "colour".', $unknown->getDisplay());

        $locked = new CommandTester($command);
        self::assertSame(Command::INVALID, $locked->execute(['public-id' => $album->getPublicId()->toString(), '--label' => 'Apple']));
        self::assertStringContainsString('Field "label" is locked and cannot be updated.', $locked->getDisplay());
    }

    public function testAMalformedPublicIdIsInvalidAndAnUnknownOneFails(): void
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn(null);
        $command = new AlbumUpdateCommand($this->support(albums: $albums));

        $malformed = new CommandTester($command);
        self::assertSame(Command::INVALID, $malformed->execute(['public-id' => 'not-valid']));
        self::assertStringContainsString('Invalid public ID format.', $malformed->getDisplay());

        $unknown = new CommandTester($command);
        $publicId = (new PublicId())->toString();
        self::assertSame(Command::FAILURE, $unknown->execute(['public-id' => $publicId]));
        self::assertStringContainsString(sprintf('Album "%s" not found.', $publicId), $unknown->getDisplay());
    }

    public function testANonIntegerYearIsInvalid(): void
    {
        $tester = new CommandTester(new AlbumUpdateCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute(['public-id' => (new PublicId())->toString(), '--year' => 'nineteen']));
        self::assertStringContainsString('--year must be an integer.', $tester->getDisplay());
    }

    public function testSongUpdatePrintsTheResourceWithArtistAndAlbumNames(): void
    {
        $song = Song::create(new Uuid(), 'Come Together', '/music/come-together.flac', 1024, 'audio/flac');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn($song);
        $songs->expects(self::exactly(2))->method('save')->with($song);
        $songs->method('getArtistNamesForSongs')->willReturn([$song->getId()->toString() => 'The Beatles']);
        $songs->method('getAlbumTitlesByIds')->willReturn([$song->getAlbumId()->toString() => 'Abbey Road']);

        $tester = new CommandTester(new SongUpdateCommand($this->support(songs: $songs), $songs));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'public-id' => $song->getPublicId()->toString(),
            '--track' => '1',
            '--explicit' => true,
            '--lock' => ['title'],
            '--json' => true,
        ]), $tester->getDisplay());
        $printed = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(SongResource::fromWithMeta($song, ['' . $song->getId() => 'The Beatles'], ['' . $song->getAlbumId() => 'Abbey Road']), $printed);
        self::assertSame(['The Beatles', 'Abbey Road', 1, true, ['title']], [$printed['artistName'], $printed['albumName'], $printed['track'], $printed['explicit'], $printed['lockedFields']]);

        $notExplicit = new CommandTester(new SongUpdateCommand($this->support(songs: $songs), $songs));
        self::assertSame(Command::SUCCESS, $notExplicit->execute(['public-id' => $song->getPublicId()->toString(), '--no-explicit' => true]));
        self::assertFalse($song->isExplicit());
    }

    public function testMovieUpdateChangesTitleYearAndSummaryAndHasNoLockOptions(): void
    {
        $movie = Movie::create(new Uuid(), 'Help', null, null);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->method('findByPublicId')->willReturn($movie);
        $movies->expects(self::once())->method('save')->with($movie);
        $command = new MovieUpdateCommand($this->support(movies: $movies));

        self::assertFalse($command->getDefinition()->hasOption('lock'));
        self::assertFalse($command->getDefinition()->hasOption('unlock'));
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'public-id' => $movie->getPublicId()->toString(),
            '--title' => 'Help!',
            '--year' => '1965',
            '--summary' => 'The Beatles on the run.',
        ]));
        self::assertSame(['Help!', 1965, 'The Beatles on the run.'], [$movie->getTitle(), $movie->getYear(), $movie->getSummary()]);
    }

    public function testArtistCreateAndUpdatePrintTheResourceTheApiReturns(): void
    {
        /** @var list<Artist> $saved */
        $saved = [];
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('save')->willReturnCallback(static function (Artist $artist) use (&$saved): void {
            $saved[] = $artist;
        });
        $artists->method('findByPublicId')->willReturnCallback(static function (PublicId $publicId) use (&$saved): ?Artist {
            foreach ($saved as $artist) {
                if ($artist->getPublicId()->equals($publicId)) {
                    return $artist;
                }
            }

            return null;
        });
        $support = $this->support(artists: $artists);

        $create = new CommandTester(new ArtistCreateCommand($support));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'The Beatles', '--country' => 'GB', '--sort-name' => 'Beatles, The', '--json' => true]));
        self::assertCount(1, $saved);
        $created = json_decode($create->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ArtistResource::from($saved[0]), $created);

        $update = new CommandTester(new ArtistUpdateCommand($support));
        self::assertSame(Command::SUCCESS, $update->execute(['public-id' => $created['publicId'], '--type' => 'Group', '--lock' => ['name', 'country'], '--json' => true]), $update->getDisplay());
        self::assertSame(ArtistResource::from($saved[0]), json_decode($update->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['name', 'country'], $saved[0]->getLockedFields());

        $locked = new CommandTester(new ArtistUpdateCommand($support));
        self::assertSame(Command::INVALID, $locked->execute(['public-id' => $created['publicId'], '--name' => 'Beatles']));

        $blank = new CommandTester(new ArtistCreateCommand($support));
        self::assertSame(Command::INVALID, $blank->execute(['name' => ' ']));
        self::assertStringContainsString('Artist name cannot be empty.', $blank->getDisplay());
    }

    private function support(
        ?AlbumPortInterface $albums = null,
        ?SongPortInterface $songs = null,
        ?MoviePortInterface $movies = null,
        ?ArtistPortInterface $artists = null,
    ): AdminCommandSupport {
        $artists ??= $this->createStub(ArtistPortInterface::class);

        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UpdateAlbumCommand::class => [new UpdateAlbumHandler($albums ?? $this->createStub(AlbumPortInterface::class))],
            UpdateSongCommand::class => [new UpdateSongHandler($songs ?? $this->createStub(SongPortInterface::class))],
            UpdateMovieCommand::class => [new UpdateMovieHandler($movies ?? $this->createStub(MoviePortInterface::class))],
            CreateArtistCommand::class => [new CreateArtistHandler($artists)],
            UpdateArtistCommand::class => [new UpdateArtistHandler($artists)],
        ]))]));
    }
}
