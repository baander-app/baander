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
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The credit use cases over an in-memory port that stores the roles of one artist per target,
 * as ArtistRepository does.
 */
final class ArtistCreditHandlersTest extends TestCase
{
    private Artist $artist;
    private Uuid $song;
    private Uuid $album;
    /** @var array<string, list<string|null>> roles by "song|<uuid>" or "album|<uuid>" */
    private array $credits = [];
    /** @var list<array{string, string|null, string}> calls to update*Role: target key, current role, new role */
    private array $changes = [];
    private ArtistPortInterface $artists;

    protected function setUp(): void
    {
        $this->artist = Artist::create('Nina Simone');
        $this->song = new Uuid();
        $this->album = new Uuid();
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Artist => $id->equals($this->artist->getPublicId()) ? $this->artist : null,
        );
        $artists->method('addSongToArtist')->willReturnCallback(fn (Uuid $artist, Uuid $song, string $role): bool => $this->add('song', $song, $role));
        $artists->method('addAlbumToArtist')->willReturnCallback(fn (Uuid $artist, Uuid $album, string $role): bool => $this->add('album', $album, $role));
        $artists->method('removeSongFromArtist')->willReturnCallback(fn (Uuid $artist, Uuid $song): bool => $this->remove('song', $song));
        $artists->method('removeAlbumFromArtist')->willReturnCallback(fn (Uuid $artist, Uuid $album): bool => $this->remove('album', $album));
        $artists->method('songCreditRoles')->willReturnCallback(fn (Uuid $artist, Uuid $song): array => $this->credits['song|' . $song->toString()] ?? []);
        $artists->method('albumCreditRoles')->willReturnCallback(fn (Uuid $artist, Uuid $album): array => $this->credits['album|' . $album->toString()] ?? []);
        $artists->method('updateSongRole')->willReturnCallback(fn (Uuid $artist, Uuid $song, ?string $current, string $role): bool => $this->change('song', $song, $current, $role));
        $artists->method('updateAlbumRole')->willReturnCallback(fn (Uuid $artist, Uuid $album, ?string $current, string $role): bool => $this->change('album', $album, $current, $role));
        $this->artists = $artists;
    }

    public function testAddingACreditTwiceKeepsOneCredit(): void
    {
        $handler = new AddArtistCreditHandler($this->artists);
        $handler(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->song->toString(), 'primary'));
        $handler(new AddArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->song->toString(), 'primary'));

        self::assertSame(['primary'], $this->credits['song|' . $this->song->toString()]);
    }

    public function testAddingACreditForAnUnknownAlbumIsNotFound(): void
    {
        $unknown = (new Uuid())->toString();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Album "%s" not found.', $unknown));

        (new AddArtistCreditHandler($this->artists))(new AddArtistCreditCommand($this->publicId(), CreditTarget::Album, $unknown, 'primary'));
    }

    /** @return iterable<string, array{object, class-string<\Throwable>, string}> */
    public static function rejectedInput(): iterable
    {
        $song = (new Uuid())->toString();
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
        $this->expectExceptionMessage(sprintf('Artist "%s" has no credit on song "%s".', $this->publicId(), $this->song->toString()));

        (new RemoveArtistCreditHandler($this->artists))(new RemoveArtistCreditCommand($this->publicId(), CreditTarget::Song, $this->song->toString()));
    }

    public function testRemovingACreditRemovesEveryRole(): void
    {
        $this->credits['album|' . $this->album->toString()] = ['featured', 'producer'];

        (new RemoveArtistCreditHandler($this->artists))(new RemoveArtistCreditCommand($this->publicId(), CreditTarget::Album, $this->album->toString()));

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

        (new ChangeArtistCreditRoleHandler($this->artists))(
            new ChangeArtistCreditRoleCommand($this->publicId(), CreditTarget::Album, $this->album->toString(), 'producer', 'featured'),
        );

        self::assertSame([['album|' . $this->album->toString(), 'featured', 'producer']], $this->changes);
    }

    public function testChangingACreditTheArtistDoesNotHaveIsNotFound(): void
    {
        $this->credits['song|' . $this->song->toString()] = ['primary'];

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Artist "%s" has no "featured" credit on song "%s".', $this->publicId(), $this->song->toString()));

        $this->changeSong('producer', 'featured');
    }

    public function testChangingARoleWithoutAnyCreditIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('has no credit on song');

        $this->changeSong('producer');
    }

    private function changeSong(string $role, ?string $current = null): void
    {
        (new ChangeArtistCreditRoleHandler($this->artists))(
            new ChangeArtistCreditRoleCommand($this->publicId(), CreditTarget::Song, $this->song->toString(), $role, $current),
        );
    }

    private function handle(object $command): void
    {
        match (true) {
            $command instanceof AddArtistCreditCommand => (new AddArtistCreditHandler($this->artists))($command),
            $command instanceof RemoveArtistCreditCommand => (new RemoveArtistCreditHandler($this->artists))($command),
            $command instanceof ChangeArtistCreditRoleCommand => (new ChangeArtistCreditRoleHandler($this->artists))($command),
            default => throw new \LogicException('Unknown command.'),
        };
    }

    private function publicId(): string
    {
        return $this->artist->getPublicId()->toString();
    }

    private function add(string $kind, Uuid $target, string $role): bool
    {
        if (!$target->equals($kind === 'song' ? $this->song : $this->album)) {
            return false;
        }
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
        unset($this->credits[$key]);

        return true;
    }

    private function change(string $kind, Uuid $target, ?string $current, string $role): bool
    {
        $this->changes[] = [$kind . '|' . $target->toString(), $current, $role];

        return true;
    }
}
