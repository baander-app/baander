<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Genre\CreateGenreCommand;
use App\Catalog\Application\Command\Genre\DeleteGenreCommand;
use App\Catalog\Application\Command\Genre\UpdateGenreCommand;
use App\Catalog\Application\CommandHandler\Genre\CreateGenreHandler;
use App\Catalog\Application\CommandHandler\Genre\DeleteGenreHandler;
use App\Catalog\Application\CommandHandler\Genre\UpdateGenreHandler;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Service\GenreParentResolver;
use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Catalog\Interface\Console\GenreAlbumAddCommand;
use App\Catalog\Interface\Console\GenreCreateCommand;
use App\Catalog\Interface\Console\GenreDeleteCommand;
use App\Catalog\Interface\Console\GenreListCommand;
use App\Catalog\Interface\Console\GenreSongRemoveCommand;
use App\Catalog\Interface\Console\GenreUpdateCommand;
use App\Catalog\Interface\Resource\GenreResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class GenreCommandsTest extends TestCase
{
    /** @var array<string, Genre> the genres the port stored, by slug */
    private array $saved = [];

    public function testListReadsEveryGenreWithTheUnrestrictedScopeAndShowsTheTree(): void
    {
        $rock = new GenreReadView(new Uuid(), 'Rock', 'rock', null, null);
        $hardRock = new GenreReadView(new Uuid(), 'Hard Rock', 'hard-rock', $rock->getId(), null);
        $grunge = new GenreReadView(new Uuid(), 'Grunge', 'grunge', $hardRock->getId(), null);
        $jazz = new GenreReadView(new Uuid(), 'Jazz', 'jazz', null, null);
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->expects(self::once())
            ->method('findAllVisible')
            ->with(self::callback(static fn (LibraryReadScope $scope): bool => $scope->isUnrestricted()))
            ->willReturn([$grunge, $hardRock, $jazz, $rock]);

        $tester = new CommandTester(new GenreListCommand($genres));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tree' => true]));
        self::assertSame(
            "Jazz (jazz)\nRock (rock)\n  Hard Rock (hard-rock)\n    Grunge (grunge)\n",
            $tester->getDisplay(true),
        );
    }

    public function testTreeListsGenresOfAStoredCycleOnce(): void
    {
        $a = new Uuid();
        $b = new Uuid();
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findAllVisible')->willReturn([
            new GenreReadView($a, 'A', 'a', $b, null),
            new GenreReadView($b, 'B', 'b', $a, null),
        ]);

        $tester = new CommandTester(new GenreListCommand($genres));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tree' => true]));
        self::assertSame("A (a)\n  B (b)\n", $tester->getDisplay(true));
    }

    public function testListTableNamesTheParentBySlug(): void
    {
        $rock = new GenreReadView(new Uuid(), 'Rock', 'rock', null, null);
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findAllVisible')->willReturn([new GenreReadView(new Uuid(), 'Hard Rock', 'hard-rock', $rock->getId(), null), $rock]);

        $tester = new CommandTester(new GenreListCommand($genres));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/Hard Rock\s+hard-rock\s+rock\s/', $tester->getDisplay());
    }

    public function testDeleteWithoutATerminalAndWithoutForceDeletesNothing(): void
    {
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn(Genre::create('Rock', 'rock'));
        $genres->expects(self::never())->method('delete');

        $tester = new CommandTester(new GenreDeleteCommand($this->support($genres)));

        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'rock'], ['interactive' => false]));
    }

    public function testDeleteWithForceDeletesAndAnUnknownGenreFails(): void
    {
        $rock = Genre::create('Rock', 'rock');
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturnCallback(static fn (string $slug): ?Genre => $slug === 'rock' ? $rock : null);
        $genres->expects(self::once())->method('delete')->with($rock);
        $command = new GenreDeleteCommand($this->support($genres));

        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute(['slug' => 'rock', '--force' => true], ['interactive' => false]));
        $missing = new CommandTester($command);
        self::assertSame(Command::FAILURE, $missing->execute(['slug' => 'nope', '--force' => true], ['interactive' => false]));
        self::assertStringContainsString('Genre "nope" not found.', $missing->getDisplay());
    }

    public function testUpdateReportsTheCycleAsInvalidInput(): void
    {
        $rock = Genre::create('Rock', 'rock');
        $child = Genre::create('Hard Rock', 'hard-rock', $rock->getId());
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn($rock);
        $genres->method('findByUuid')->willReturn($child);
        $genres->method('isDescendantOf')->willReturn(true);
        $genres->expects(self::never())->method('save');

        $tester = new CommandTester(new GenreUpdateCommand($this->support($genres)));

        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'rock', '--parent' => $child->getId()->toString()]));
        self::assertStringContainsString(UpdateGenreHandler::CYCLE_MESSAGE, (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testLinkCommandsReportAnUnknownGenreAndAMalformedId(): void
    {
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturnCallback(static fn (string $slug): ?Genre => $slug === 'rock' ? Genre::create('Rock', 'rock') : null);
        $genres->expects(self::never())->method('addAlbumToGenre');
        $genres->expects(self::never())->method('removeSongFromGenre');

        $unknown = new CommandTester(new GenreAlbumAddCommand($genres));
        self::assertSame(Command::FAILURE, $unknown->execute(['slug' => 'nope', 'album-id' => (new Uuid())->toString()]));
        self::assertStringContainsString('Genre "nope" not found.', $unknown->getDisplay());

        $malformed = new CommandTester(new GenreSongRemoveCommand($genres));
        self::assertSame(Command::INVALID, $malformed->execute(['slug' => 'rock', 'song-id' => 'not-a-uuid']));
        self::assertStringContainsString('Invalid song ID format.', $malformed->getDisplay());
    }

    public function testAlbumAddLinksThroughThePort(): void
    {
        $rock = Genre::create('Rock', 'rock');
        $album = new Uuid();
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn($rock);
        $genres->expects(self::once())->method('addAlbumToGenre')->with($rock->getId(), self::callback(static fn (Uuid $id): bool => $id->equals($album)))->willReturn(true);

        $tester = new CommandTester(new GenreAlbumAddCommand($genres));

        self::assertSame(Command::SUCCESS, $tester->execute(['slug' => 'rock', 'album-id' => $album->toString()]));
    }

    public function testAnAlbumThePortDoesNotFindIsNotFound(): void
    {
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn(Genre::create('Rock', 'rock'));
        $genres->method('addAlbumToGenre')->willReturn(false);

        $tester = new CommandTester(new GenreAlbumAddCommand($genres));

        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'rock', 'album-id' => (new Uuid())->toString()]));
        self::assertStringContainsString('Album not found.', $tester->getDisplay());
    }

    public function testCreateAndUpdateJsonPrintTheGenreTheApiReturns(): void
    {
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturnCallback(fn (string $slug): ?Genre => $this->saved[$slug] ?? null);
        $genres->method('save')->willReturnCallback(function (Genre $genre): void {
            $this->saved[$genre->getSlug()] = $genre;
        });

        $create = new CommandTester(new GenreCreateCommand($this->support($genres)));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Rock', 'slug' => 'rock', '--json' => true]));
        $created = json_decode($create->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(GenreResource::from($this->saved['rock']), $created);
        self::assertSame(['uuid', 'name', 'slug', 'parentId', 'mbid'], array_keys($created));

        $update = new CommandTester(new GenreUpdateCommand($this->support($genres)));
        self::assertSame(Command::SUCCESS, $update->execute(['slug' => 'rock', '--name' => 'Rock and Roll', '--json' => true]));
        $updated = json_decode($update->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($created['uuid'], $updated['uuid']);
        self::assertSame('Rock and Roll', $updated['name']);
    }

    public function testDeleteAndLinkJsonPrintNothingAsTheApiAnswersNoContent(): void
    {
        $rock = Genre::create('Rock', 'rock');
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn($rock);
        $genres->method('addAlbumToGenre')->willReturn(true);

        $delete = new CommandTester(new GenreDeleteCommand($this->support($genres)));
        self::assertSame(Command::SUCCESS, $delete->execute(['slug' => 'rock', '--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame('', $delete->getDisplay());

        $link = new CommandTester(new GenreAlbumAddCommand($genres));
        self::assertSame(Command::SUCCESS, $link->execute(['slug' => 'rock', 'album-id' => (new Uuid())->toString(), '--json' => true]));
        self::assertSame('', $link->getDisplay());
    }

    public function testDeleteJsonStillNeedsForceWithoutATerminal(): void
    {
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn(Genre::create('Rock', 'rock'));
        $genres->expects(self::never())->method('delete');

        $tester = new CommandTester(new GenreDeleteCommand($this->support($genres)));

        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'rock', '--json' => true], ['interactive' => false, 'capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
    }

    private function support(GenrePortInterface $genres): AdminCommandSupport
    {
        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CreateGenreCommand::class => [new CreateGenreHandler($genres, new GenreParentResolver($genres))],
            UpdateGenreCommand::class => [new UpdateGenreHandler($genres, new GenreParentResolver($genres))],
            DeleteGenreCommand::class => [new DeleteGenreHandler($genres)],
        ]))]));
    }
}
