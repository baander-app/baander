<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\Query\Album\AlbumDeletePreview;
use App\Catalog\Application\Query\Album\GetAlbumDeletePreviewQuery;
use App\Catalog\Application\Query\FileDeletionPreview;
use App\Catalog\Interface\Console\AlbumDeleteCommand;
use App\Catalog\Interface\Console\ArtistDeleteCommand;
use App\Catalog\Interface\Console\MovieDeleteCommand;
use App\Catalog\Interface\Console\SongDeleteCommand;
use App\Catalog\Interface\Resource\AlbumDeletePreviewResource;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class CatalogDeleteCommandsTest extends TestCase
{
    private const string ALBUM = '01JZ8Y9K2M3N4P5Q6R7S8T9V0W';

    /** @var list<object> the messages the commands dispatched */
    private array $dispatched = [];
    private ?\Throwable $failure = null;
    private CatalogDeletionResult $result;

    protected function setUp(): void
    {
        $this->result = new CatalogDeletionResult(['albums' => 1, 'songs' => 2, 'coverImages' => 1], ['/music/a.flac', '/music/b.flac']);
    }

    public function testDeleteFilesWithoutForceOnATerminalExitsInvalidAndDispatchesNothing(): void
    {
        foreach ([AlbumDeleteCommand::class, SongDeleteCommand::class] as $class) {
            $tester = new CommandTester(new $class($this->support()));
            $tester->setInputs(['yes']);

            self::assertSame(Command::INVALID, $tester->execute(['public-id' => self::ALBUM, '--delete-files' => true]), $class);
            self::assertStringContainsString('needs --force', $tester->getDisplay());
        }

        self::assertSame([], $this->dispatched);
    }

    public function testWithoutATerminalADeleteNeedsForceEvenWithJson(): void
    {
        foreach ([AlbumDeleteCommand::class, SongDeleteCommand::class, MovieDeleteCommand::class, ArtistDeleteCommand::class] as $class) {
            $tester = new CommandTester(new $class($this->support()));

            self::assertSame(Command::INVALID, $tester->execute(['public-id' => self::ALBUM, '--json' => true], ['interactive' => false, 'capture_stderr_separately' => true]), $class);
            self::assertSame('', $tester->getDisplay());
        }

        self::assertSame([], $this->dispatched);
    }

    public function testTheAlbumDeleteDispatchesTheOptionsAndPrintsTheApiData(): void
    {
        $tester = new CommandTester(new AlbumDeleteCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(
            ['public-id' => self::ALBUM, '--delete-files' => true, '--keep-cover' => true, '--force' => true, '--json' => true],
            ['interactive' => false],
        ));

        self::assertEquals([new DeleteAlbumCommand(self::ALBUM, deleteFiles: true, deleteCover: false)], $this->dispatched);
        self::assertSame(CatalogDeletionResource::from($this->result), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAConfirmedAlbumDeleteKeepsTheFilesAndTheDefaultCover(): void
    {
        $tester = new CommandTester(new AlbumDeleteCommand($this->support()));
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => self::ALBUM]));

        self::assertEquals([new DeleteAlbumCommand(self::ALBUM)], $this->dispatched);
        self::assertStringContainsString('albums: 1, songs: 2, coverImages: 1', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAFileLeftOnDiskExitsWithFailureAndListsIt(): void
    {
        $this->result = new CatalogDeletionResult(['songs' => 1], left: [['path' => '/music/a.flac', 'reason' => 'unlink_failed', 'detail' => 'Permission denied']]);

        $plain = new CommandTester(new SongDeleteCommand($this->support()));
        self::assertSame(Command::FAILURE, $plain->execute(['public-id' => self::ALBUM, '--delete-files' => true, '--force' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('/music/a.flac (unlink_failed: Permission denied)', $plain->getErrorOutput());

        $json = new CommandTester(new SongDeleteCommand($this->support()));
        self::assertSame(Command::FAILURE, $json->execute(['public-id' => self::ALBUM, '--delete-files' => true, '--force' => true, '--json' => true]));
        self::assertSame(CatalogDeletionResource::from($this->result), json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        self::assertEquals(new DeleteSongCommand(self::ALBUM, deleteFile: true), $this->dispatched[1]);
    }

    public function testDryRunPrintsThePreviewDataAndDeletesNothing(): void
    {
        $tester = new CommandTester(new AlbumDeleteCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => self::ALBUM, '--dry-run' => true, '--delete-files' => true, '--json' => true]));

        self::assertEquals([new GetAlbumDeletePreviewQuery(self::ALBUM, deleteFiles: true)], $this->dispatched);
        self::assertSame(AlbumDeletePreviewResource::from($this->preview()), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));

        $plain = new CommandTester(new AlbumDeleteCommand($this->support()));
        self::assertSame(Command::SUCCESS, $plain->execute(['public-id' => self::ALBUM, '--dry-run' => true, '--delete-files' => true]));
        self::assertStringContainsString('outside_root', $plain->getDisplay());
        self::assertStringContainsString('would be refused', $plain->getDisplay());
        self::assertStringContainsString('Dry run: nothing was changed.', $plain->getDisplay());
    }

    public function testOutcomesMapToExitCodes(): void
    {
        $this->failure = new NotFoundException('Movie "x" not found.');
        $missing = new CommandTester(new MovieDeleteCommand($this->support()));
        self::assertSame(Command::FAILURE, $missing->execute(['public-id' => self::ALBUM, '--force' => true]));
        self::assertStringContainsString('Movie "x" not found.', $missing->getDisplay());

        $this->failure = new InvalidInputException('Invalid public ID format.');
        $malformed = new CommandTester(new ArtistDeleteCommand($this->support()));
        self::assertSame(Command::INVALID, $malformed->execute(['public-id' => 'nope', '--force' => true]));

        self::assertEquals([new DeleteMovieCommand(self::ALBUM), new DeleteArtistCommand('nope')], $this->dispatched);
    }

    private function support(): AdminCommandSupport
    {
        $handle = function (object $message): mixed {
            $this->dispatched[] = $message;
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $message instanceof GetAlbumDeletePreviewQuery ? $this->preview() : $this->result;
        };

        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            DeleteAlbumCommand::class => [$handle],
            DeleteSongCommand::class => [$handle],
            DeleteMovieCommand::class => [$handle],
            DeleteArtistCommand::class => [$handle],
            GetAlbumDeletePreviewQuery::class => [$handle],
        ]))]));
    }

    private function preview(): AlbumDeletePreview
    {
        return new AlbumDeletePreview(
            publicId: self::ALBUM,
            title: 'Album',
            songCount: 2,
            totalSize: 14,
            coverImageId: null,
            playlistNames: [],
            fileDeletion: new FileDeletionPreview(false, false, [
                ['path' => '/music/a.flac', 'verdict' => 'deletable', 'directory' => null],
                ['path' => '/elsewhere/b.flac', 'verdict' => 'outside_root', 'directory' => null],
            ]),
        );
    }
}
