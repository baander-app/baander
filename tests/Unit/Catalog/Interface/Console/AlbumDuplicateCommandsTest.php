<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Catalog\Interface\Console\AlbumDuplicatesCommand;
use App\Catalog\Interface\Console\AlbumMergeCommand;
use App\Catalog\Interface\Resource\AlbumResource;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AlbumDuplicateCommandsTest extends TestCase
{
    private const string LIBRARY = '0199bf3c-8a00-7000-8000-0000000000f6';

    public function testDuplicatesJsonPrintsTheApiPayload(): void
    {
        $groups = [self::group()];
        $duplicates = $this->createMock(AlbumDuplicatePortInterface::class);
        $duplicates->expects(self::once())
            ->method('findDuplicates')
            ->with(self::callback(static fn (Uuid $library): bool => $library->toString() === self::LIBRARY))
            ->willReturn($groups);

        $tester = new CommandTester(new AlbumDuplicatesCommand($duplicates));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => self::LIBRARY, '--json' => true]));
        self::assertSame(
            json_decode(json_encode(DuplicateGroupResource::collection($groups), JSON_THROW_ON_ERROR), true),
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testDuplicatesTableShowsEachAlbumOfEachGroup(): void
    {
        $duplicates = $this->createStub(AlbumDuplicatePortInterface::class);
        $duplicates->method('findDuplicates')->willReturn([self::group()]);

        $tester = new CommandTester(new AlbumDuplicatesCommand($duplicates));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => self::LIBRARY]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/1\s+92%\s+keptAlbumPublicId0001\s+Blue Train\s+1957\s+Blue Note\s+John Coltrane/', $display);
        self::assertMatchesRegularExpression('/mergedAlbumPublicId02\s+Blue Train \(Remaster\)\s+1957\s+-\s+John Coltrane, Lee Morgan/', $display);
    }

    public function testDuplicatesReportsALibraryWithoutDuplicates(): void
    {
        $duplicates = $this->createStub(AlbumDuplicatePortInterface::class);
        $duplicates->method('findDuplicates')->willReturn([]);

        $tester = new CommandTester(new AlbumDuplicatesCommand($duplicates));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => self::LIBRARY]));
        self::assertStringContainsString('No duplicate albums found in the library.', $tester->getDisplay());
    }

    public function testDuplicatesRejectsALibraryIdThatIsNotAUuid(): void
    {
        $duplicates = $this->createMock(AlbumDuplicatePortInterface::class);
        $duplicates->expects(self::never())->method('findDuplicates');

        $tester = new CommandTester(new AlbumDuplicatesCommand($duplicates));

        self::assertSame(Command::INVALID, $tester->execute(['library' => 'jazz']));
        self::assertStringContainsString('The library ID must be a UUID.', $tester->getDisplay());
    }

    public function testMergeMergesTheSourceIntoTheTargetThroughTheMergePort(): void
    {
        $library = Uuid::fromString(self::LIBRARY);
        $target = Album::create($library, 'Blue Train', 'album');
        $source = Album::create($library, 'Blue Train (Remaster)', 'album');
        $merge = $this->createMock(AlbumMergePortInterface::class);
        $merge->expects(self::once())
            ->method('mergeAlbums')
            ->with(
                self::callback(static fn (Uuid $id): bool => $id->equals($target->getId())),
                self::callback(static fn (Uuid $id): bool => $id->equals($source->getId())),
            )
            ->willReturn($target);

        $tester = new CommandTester(new AlbumMergeCommand($this->albums($target, $source), $merge));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'target' => $target->getPublicId()->toString(),
            'source' => $source->getPublicId()->toString(),
            '--force' => true,
        ], ['interactive' => false]));
        self::assertStringContainsString(
            sprintf('Merged "Blue Train (Remaster)" into "Blue Train" (%s).', $target->getPublicId()->toString()),
            preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '',
        );
    }

    public function testMergeWithoutATerminalNeedsForce(): void
    {
        $library = Uuid::fromString(self::LIBRARY);
        $target = Album::create($library, 'Blue Train', 'album');
        $source = Album::create($library, 'Blue Train (Remaster)', 'album');
        $merge = $this->createMock(AlbumMergePortInterface::class);
        $merge->expects(self::never())->method('mergeAlbums');

        $tester = new CommandTester(new AlbumMergeCommand($this->albums($target, $source), $merge));

        self::assertSame(Command::INVALID, $tester->execute([
            'target' => $target->getPublicId()->toString(),
            'source' => $source->getPublicId()->toString(),
        ], ['interactive' => false]));
    }

    public function testMergeFailsForAnUnknownAlbum(): void
    {
        $target = Album::create(Uuid::fromString(self::LIBRARY), 'Blue Train', 'album');
        $merge = $this->createMock(AlbumMergePortInterface::class);
        $merge->expects(self::never())->method('mergeAlbums');

        $tester = new CommandTester(new AlbumMergeCommand($this->albums($target), $merge));

        self::assertSame(Command::FAILURE, $tester->execute([
            'target' => $target->getPublicId()->toString(),
            'source' => (new PublicId())->toString(),
            '--force' => true,
        ]));
        self::assertStringContainsString('Source album not found.', $tester->getDisplay());
    }

    public function testMergeRejectsAnIdThatIsNotAPublicId(): void
    {
        $merge = $this->createMock(AlbumMergePortInterface::class);
        $merge->expects(self::never())->method('mergeAlbums');

        $tester = new CommandTester(new AlbumMergeCommand($this->createStub(AlbumPortInterface::class), $merge));

        self::assertSame(Command::INVALID, $tester->execute([
            'target' => '0199bf3c-8a00-7000-8000-0000000000a1',
            'source' => 'mergedAlbumPublicId02',
            '--force' => true,
        ]));
        self::assertStringContainsString('PublicId must be 21 characters long', $tester->getDisplay());
    }

    public function testMergeRejectedByTheMergeRulesExitsInvalid(): void
    {
        $target = Album::create(Uuid::fromString(self::LIBRARY), 'Blue Train', 'album');
        $source = Album::create(Uuid::v7(), 'Blue Train', 'album');
        $merge = $this->createStub(AlbumMergePortInterface::class);
        $merge->method('mergeAlbums')->willThrowException(new InvalidArgumentException('Albums must be in the same library to merge.'));

        $tester = new CommandTester(new AlbumMergeCommand($this->albums($target, $source), $merge));

        self::assertSame(Command::INVALID, $tester->execute([
            'target' => $target->getPublicId()->toString(),
            'source' => $source->getPublicId()->toString(),
            '--force' => true,
        ]));
        self::assertStringContainsString('Albums must be in the same library to merge.', $tester->getDisplay());
    }

    private function albums(Album ...$albums): AlbumPortInterface
    {
        $port = $this->createStub(AlbumPortInterface::class);
        $port->method('findByPublicId')->willReturnCallback(static function (PublicId $publicId) use ($albums): ?Album {
            foreach ($albums as $album) {
                if ($album->getPublicId()->equals($publicId)) {
                    return $album;
                }
            }

            return null;
        });

        return $port;
    }

    private static function group(): DuplicateGroup
    {
        $library = Uuid::fromString(self::LIBRARY);
        $kept = Album::create($library, 'Blue Train', 'album', year: 1957, label: 'Blue Note');
        $merged = Album::create($library, 'Blue Train (Remaster)', 'album', year: 1957);
        $keptData = ['publicId' => 'keptAlbumPublicId0001'] + AlbumResource::from($kept);
        $mergedData = ['publicId' => 'mergedAlbumPublicId02'] + AlbumResource::from($merged);
        $keptData['coverImage'] = null;
        $keptData['artists'] = [['name' => 'John Coltrane', 'role' => null]];
        $mergedData['coverImage'] = null;
        $mergedData['artists'] = [['name' => 'John Coltrane', 'role' => null], ['name' => 'Lee Morgan', 'role' => 'featured']];

        return new DuplicateGroup([$kept->getId(), $merged->getId()], 0.92, [$keptData, $mergedData]);
    }
}
