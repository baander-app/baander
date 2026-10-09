<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Catalog\Application\CommandHandler\Artist\AddArtistCreditHandler;
use App\Catalog\Application\CommandHandler\Artist\ChangeArtistCreditRoleHandler;
use App\Catalog\Application\CommandHandler\Artist\RemoveArtistCreditHandler;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Interface\Console\ArtistAlbumAddCommand;
use App\Catalog\Interface\Console\ArtistAlbumRemoveCommand;
use App\Catalog\Interface\Console\ArtistAlbumRoleCommand;
use App\Catalog\Interface\Console\ArtistSongAddCommand;
use App\Catalog\Interface\Console\ArtistSongRemoveCommand;
use App\Catalog\Interface\Console\ArtistSongRoleCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class ArtistCreditCommandsTest extends TestCase
{
    private Artist $artist;
    private string $song;

    protected function setUp(): void
    {
        $this->artist = Artist::create('Nina Simone');
        $this->song = (new Uuid())->toString();
    }

    public function testSongAddCreditsTheArtistAndPrintsNothingWithJson(): void
    {
        $artists = $this->artists();
        $artists->expects(self::exactly(2))->method('addSongToArtist')
            ->with($this->artist->getId(), Uuid::fromString($this->song), 'featured')
            ->willReturn(true);
        $support = $this->support($artists);

        $plain = new CommandTester(new ArtistSongAddCommand($support));
        self::assertSame(Command::SUCCESS, $plain->execute(['public-id' => $this->publicId(), 'song-id' => $this->song, 'role' => 'featured']));
        self::assertStringContainsString(sprintf('is credited as featured on song %s', $this->song), $this->normalized($plain->getDisplay()));

        $json = new CommandTester(new ArtistSongAddCommand($support));
        self::assertSame(Command::SUCCESS, $json->execute(['public-id' => $this->publicId(), 'song-id' => $this->song, 'role' => 'featured', '--json' => true]));
        self::assertSame('', $json->getDisplay());
    }

    public function testAnInvalidRoleExitsTwoAndAnUnknownArtistExitsOne(): void
    {
        $artists = $this->artists();
        $artists->expects(self::never())->method('addAlbumToArtist');
        $support = $this->support($artists);

        $role = new CommandTester(new ArtistAlbumAddCommand($support));
        self::assertSame(Command::INVALID, $role->execute(['public-id' => $this->publicId(), 'album-id' => $this->song, 'role' => 'singer']));
        self::assertStringContainsString('Invalid role "singer".', $role->getDisplay());

        $unknown = new CommandTester(new ArtistAlbumAddCommand($support));
        self::assertSame(Command::FAILURE, $unknown->execute(['public-id' => (new PublicId())->toString(), 'album-id' => $this->song, 'role' => 'primary']));
        self::assertStringContainsString('not found', $unknown->getDisplay());
    }

    public function testRemovingAMissingCreditExitsOne(): void
    {
        $artists = $this->artists();
        $artists->method('removeAlbumFromArtist')->willReturn(false);
        $artists->expects(self::once())->method('removeSongFromArtist')->willReturn(true);
        $support = $this->support($artists);

        $album = new CommandTester(new ArtistAlbumRemoveCommand($support));
        self::assertSame(Command::FAILURE, $album->execute(['public-id' => $this->publicId(), 'album-id' => $this->song]));
        self::assertStringContainsString('has no credit on album', $this->normalized($album->getDisplay()));

        $song = new CommandTester(new ArtistSongRemoveCommand($support));
        self::assertSame(Command::SUCCESS, $song->execute(['public-id' => $this->publicId(), 'song-id' => $this->song]));
    }

    public function testRoleChangeNeedsFromWhenThePairHasSeveralRoles(): void
    {
        $artists = $this->artists();
        $artists->method('songCreditRoles')->willReturn(['featured', 'producer']);
        $artists->method('albumCreditRoles')->willReturn(['featured', 'producer']);
        $artists->expects(self::never())->method('updateSongRole');
        $artists->expects(self::once())->method('updateAlbumRole')
            ->with($this->artist->getId(), Uuid::fromString($this->song), 'featured', 'producer')
            ->willReturn(true);
        $support = $this->support($artists);

        $ambiguous = new CommandTester(new ArtistSongRoleCommand($support));
        self::assertSame(Command::INVALID, $ambiguous->execute(['public-id' => $this->publicId(), 'song-id' => $this->song, 'role' => 'primary']));
        self::assertStringContainsString('(featured, producer)', $this->normalized($ambiguous->getDisplay()));

        $named = new CommandTester(new ArtistAlbumRoleCommand($support));
        self::assertSame(Command::SUCCESS, $named->execute(['public-id' => $this->publicId(), 'album-id' => $this->song, 'role' => 'producer', '--from' => 'featured']));
        self::assertStringContainsString('is credited as producer on album', $this->normalized($named->getDisplay()));
    }

    private function publicId(): string
    {
        return $this->artist->getPublicId()->toString();
    }

    private function artists(): ArtistPortInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Artist => $id->equals($this->artist->getPublicId()) ? $this->artist : null,
        );

        return $artists;
    }

    private function support(ArtistPortInterface $artists): AdminCommandSupport
    {
        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            AddArtistCreditCommand::class => [new AddArtistCreditHandler($artists)],
            RemoveArtistCreditCommand::class => [new RemoveArtistCreditHandler($artists)],
            ChangeArtistCreditRoleCommand::class => [new ChangeArtistCreditRoleHandler($artists)],
        ]))]));
    }

    private function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
