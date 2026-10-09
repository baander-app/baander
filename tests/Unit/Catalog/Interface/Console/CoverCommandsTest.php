<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Interface\Console\AlbumCoverRemoveCommand;
use App\Catalog\Interface\Console\AlbumCoverSetCommand;
use App\Catalog\Interface\Console\ArtistCoverRemoveCommand;
use App\Catalog\Interface\Console\ArtistCoverSetCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Catalog\Application\CommandHandler\Cover\CoverTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CoverCommandsTest extends CoverTestCase
{
    public function testSetJsonPrintsTheUploadResponseData(): void
    {
        $artist = $this->artist();
        $tester = new CommandTester(new ArtistCoverSetCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => $artist->getPublicId()->toString(), 'path' => $this->jpeg(8, 6), '--json' => true]));

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $image = $this->images->findByPublicId(PublicId::fromString($data['publicId']));
        self::assertNotNull($image);
        self::assertSame([
            'publicId' => $image->getPublicId()->toString(),
            'url' => '/api/images/' . $image->getPublicId()->toString() . '/file',
            'size' => $image->getSize(),
            'width' => 8,
            'height' => 6,
        ], $data);
        self::assertSame($image->getId()->toString(), $this->storedCovers[$artist->getPublicId()->toString()]);
    }

    public function testSetCopiesTheFileAndReportsTheImage(): void
    {
        $album = $this->album();
        $source = $this->jpeg(4, 3);
        $tester = new CommandTester(new AlbumCoverSetCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => $album->getPublicId()->toString(), 'path' => $source]));

        self::assertFileExists($source, 'the given file is copied, not moved');
        self::assertCount(1, $this->storedFiles());
        self::assertStringContainsString('Album cover set', $tester->getDisplay());
        self::assertStringContainsString('4x3', $tester->getDisplay());
    }

    public function testSetReportsBadInputAndAnUnknownOwner(): void
    {
        $album = $this->album();

        $missing = new CommandTester(new AlbumCoverSetCommand($this->support()));
        self::assertSame(Command::INVALID, $missing->execute(['public-id' => $album->getPublicId()->toString(), 'path' => $this->root . '/nope.jpg']));
        self::assertStringContainsString('The cover file does not exist or cannot be read.', $missing->getDisplay());

        $malformed = new CommandTester(new AlbumCoverSetCommand($this->support()));
        self::assertSame(Command::INVALID, $malformed->execute(['public-id' => 'nope', 'path' => $this->jpeg(2, 2)]));

        $unknown = new CommandTester(new ArtistCoverSetCommand($this->support()));
        self::assertSame(Command::FAILURE, $unknown->execute(['public-id' => (new PublicId())->toString(), 'path' => $this->jpeg(2, 2)]));
        self::assertSame([], $this->storedFiles());
    }

    public function testRemoveWithoutATerminalNeedsForceEvenWithJson(): void
    {
        $album = $this->album();
        $image = $this->existingCover($album, 'images/album/cover.jpg');
        $tester = new CommandTester(new AlbumCoverRemoveCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute(
            ['public-id' => $album->getPublicId()->toString(), '--json' => true],
            ['interactive' => false, 'capture_stderr_separately' => true],
        ));

        self::assertSame('', $tester->getDisplay());
        self::assertNotNull($this->images->findByUuid($image->getId()));
    }

    public function testRemoveWithForceRemovesTheCoverAndJsonPrintsNothing(): void
    {
        $album = $this->album();
        $this->existingCover($album, 'images/album/cover.jpg');
        $tester = new CommandTester(new AlbumCoverRemoveCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(
            ['public-id' => $album->getPublicId()->toString(), '--force' => true, '--json' => true],
            ['interactive' => false],
        ));

        self::assertSame('', $tester->getDisplay());
        self::assertSame([], $this->storedFiles());
        self::assertNull($this->storedCovers[$album->getPublicId()->toString()]);
    }

    public function testRemovingAMissingCoverFails(): void
    {
        $artist = $this->artist();
        $tester = new CommandTester(new ArtistCoverRemoveCommand($this->support()));

        self::assertSame(Command::FAILURE, $tester->execute(['public-id' => $artist->getPublicId()->toString(), '--force' => true], ['interactive' => false]));
        self::assertStringContainsString('has no cover.', $tester->getDisplay());
    }

    private function support(): AdminCommandSupport
    {
        return new AdminCommandSupport($this->bus());
    }
}
