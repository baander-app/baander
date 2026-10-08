<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Genre;

use App\Catalog\Application\Command\Genre\CreateGenreCommand;
use App\Catalog\Application\Command\Genre\DeleteGenreCommand;
use App\Catalog\Application\Command\Genre\UpdateGenreCommand;
use App\Catalog\Application\CommandHandler\Genre\CreateGenreHandler;
use App\Catalog\Application\CommandHandler\Genre\DeleteGenreHandler;
use App\Catalog\Application\CommandHandler\Genre\UpdateGenreHandler;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Service\GenreParentResolver;
use App\Catalog\Domain\Model\Genre;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class GenreHandlersTest extends TestCase
{
    /** @var array<string, Genre> by slug */
    private array $stored = [];
    /** @var list<Genre> */
    private array $saved = [];
    /** @var list<Genre> */
    private array $deleted = [];
    /** @var array<string, string> child UUID => parent UUID */
    private array $parents = [];
    private GenrePortInterface&Stub $genres;

    protected function setUp(): void
    {
        $this->genres = $this->createStub(GenrePortInterface::class);
        $this->genres->method('findBySlug')->willReturnCallback(fn (string $slug): ?Genre => $this->stored[$slug] ?? null);
        $this->genres->method('findByUuid')->willReturnCallback(function (Uuid $id): ?Genre {
            foreach ($this->stored as $genre) {
                if ($genre->getId()->equals($id)) {
                    return $genre;
                }
            }

            return null;
        });
        $this->genres->method('isDescendantOf')->willReturnCallback(function (Uuid $ancestor, Uuid $id): bool {
            for ($current = $id->toString(); $current !== null; $current = $this->parents[$current] ?? null) {
                if ($current === $ancestor->toString()) {
                    return true;
                }
            }

            return false;
        });
        $this->genres->method('save')->willReturnCallback(function (Genre $genre): void {
            $this->saved[] = $genre;
        });
        $this->genres->method('delete')->willReturnCallback(function (Genre $genre): void {
            $this->deleted[] = $genre;
        });
    }

    public function testCreateSavesARootGenre(): void
    {
        $genre = $this->create(new CreateGenreCommand('Rock', 'rock'));

        self::assertSame([$genre], $this->saved);
        self::assertSame('Rock', $genre->getName());
        self::assertNull($genre->getParent());
    }

    public function testCreateBelowAnExistingParent(): void
    {
        $rock = $this->store('Rock', 'rock');

        $genre = $this->create(new CreateGenreCommand('Hard Rock', 'hard-rock', $rock->getId()->toString()));

        self::assertTrue($rock->getId()->equals($genre->getParent()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidParents(): iterable
    {
        yield 'unknown parent' => [(new Uuid())->toString(), 'Parent genre not found.'];
        yield 'malformed parent' => ['not-a-uuid', 'Invalid parent ID format.'];
    }

    #[DataProvider('invalidParents')]
    public function testCreateRejectsAParentThatIsNotAGenre(string $parentId, string $message): void
    {
        $this->expectRejection(InvalidInputException::class, $message, fn () => $this->create(new CreateGenreCommand('Orphan', 'orphan', $parentId)));
    }

    public function testCreateReportsTheDomainRuleAsInvalidInput(): void
    {
        $this->expectRejection(
            InvalidInputException::class,
            'Genre slug "Not A Slug" is invalid. Slugs must contain only lowercase letters, numbers, and hyphens.',
            fn () => $this->create(new CreateGenreCommand('Rock', 'Not A Slug')),
        );
    }

    public function testCreateWithATakenSlugIsAConflict(): void
    {
        $this->store('Rock', 'rock');

        $this->expectRejection(
            ConflictException::class,
            'A genre with the slug "rock" already exists.',
            fn () => $this->create(new CreateGenreCommand('Rock again', 'rock')),
        );
    }

    public function testUpdateToAnotherGenresSlugIsAConflict(): void
    {
        $this->store('Rock', 'rock');
        $this->store('Blues', 'blues');

        $this->expectRejection(
            ConflictException::class,
            'A genre with the slug "rock" already exists.',
            fn () => $this->update(new UpdateGenreCommand('blues', newSlug: 'rock')),
        );
    }

    public function testUpdateKeepingItsOwnSlugIsNoConflict(): void
    {
        $this->store('Rock', 'rock');

        $genre = $this->update(new UpdateGenreCommand('rock', name: 'Rock Music', newSlug: 'rock'));

        self::assertSame(['Rock Music', 'rock'], [$genre->getName(), $genre->getSlug()]);
    }

    public function testUpdateRejectsMakingAGenreTheChildOfItsDescendant(): void
    {
        $rock = $this->store('Rock', 'rock');
        $hardRock = $this->store('Hard Rock', 'hard-rock', $rock);
        $grunge = $this->store('Grunge', 'grunge', $hardRock);

        $this->expectRejection(
            InvalidInputException::class,
            UpdateGenreHandler::CYCLE_MESSAGE,
            fn () => $this->update(new UpdateGenreCommand('rock', name: 'Renamed', parentId: $grunge->getId()->toString())),
        );
        self::assertNull($rock->getParent());
    }

    public function testUpdateRejectsMakingAGenreItsOwnParent(): void
    {
        $rock = $this->store('Rock', 'rock');

        $this->expectRejection(
            InvalidInputException::class,
            UpdateGenreHandler::CYCLE_MESSAGE,
            fn () => $this->update(new UpdateGenreCommand('rock', parentId: $rock->getId()->toString())),
        );
    }

    public function testUpdateRejectsAnUnknownParent(): void
    {
        $this->store('Rock', 'rock');

        $this->expectRejection(
            InvalidInputException::class,
            'Parent genre not found.',
            fn () => $this->update(new UpdateGenreCommand('rock', parentId: (new Uuid())->toString())),
        );
    }

    public function testUpdateChangesOnlyTheGivenFields(): void
    {
        $rock = $this->store('Rock', 'rock');
        $blues = $this->store('Blues', 'blues');
        $mbid = '0e3fc579-2d24-4f20-9dae-736e1ec78798';

        $genre = $this->update(new UpdateGenreCommand('blues', newSlug: 'blues-rock', parentId: $rock->getId()->toString(), mbid: $mbid));

        self::assertSame($blues, $genre);
        self::assertSame(['Blues', 'blues-rock', $mbid], [$genre->getName(), $genre->getSlug(), $genre->getMbid()]);
        self::assertTrue($rock->getId()->equals($genre->getParent()));
        self::assertSame([$blues], $this->saved);
    }

    public function testUpdateOfAnUnknownGenreIsNotFound(): void
    {
        $this->expectRejection(NotFoundException::class, 'Genre "nope" not found.', fn () => $this->update(new UpdateGenreCommand('nope', name: 'X')));
    }

    public function testDeleteRemovesTheGenre(): void
    {
        $rock = $this->store('Rock', 'rock');

        (new DeleteGenreHandler($this->genres))(new DeleteGenreCommand('rock'));

        self::assertSame([$rock], $this->deleted);
    }

    public function testDeleteOfAnUnknownGenreIsNotFound(): void
    {
        $this->expectRejection(NotFoundException::class, 'Genre "nope" not found.', fn () => (new DeleteGenreHandler($this->genres))(new DeleteGenreCommand('nope')));
        self::assertSame([], $this->deleted);
    }

    private function create(CreateGenreCommand $command): Genre
    {
        return (new CreateGenreHandler($this->genres, new GenreParentResolver($this->genres)))($command);
    }

    private function update(UpdateGenreCommand $command): Genre
    {
        return (new UpdateGenreHandler($this->genres, new GenreParentResolver($this->genres)))($command);
    }

    private function store(string $name, string $slug, ?Genre $parent = null): Genre
    {
        $genre = Genre::create($name, $slug, $parent?->getId());
        $this->stored[$slug] = $genre;
        if ($parent !== null) {
            $this->parents[$genre->getId()->toString()] = $parent->getId()->toString();
        }

        return $genre;
    }

    /**
     * @param class-string<\Throwable> $class
     * @param callable(): mixed        $action
     */
    private function expectRejection(string $class, string $message, callable $action): void
    {
        try {
            $action();
            self::fail(sprintf('Expected %s.', $class));
        } catch (\Throwable $exception) {
            self::assertInstanceOf($class, $exception);
            self::assertSame($message, $exception->getMessage());
        }
        self::assertSame([], $this->saved, 'A rejected change saves nothing.');
    }
}
