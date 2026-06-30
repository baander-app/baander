<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Domain\Model;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Model\LibraryState;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LibraryTest extends TestCase
{
    private function validSlug(): LibrarySlug
    {
        return new LibrarySlug('my-library');
    }

    private function validPath(): LibraryPath
    {
        return new LibraryPath('/media/music');
    }

    public function testCreateInitializesWithDefaults(): void
    {
        $before = new \DateTimeImmutable();

        $library = Library::create(
            name: 'My Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $this->assertSame('My Music', $library->getName());
        $this->assertSame(0, $library->getSortOrder());
        $this->assertNull($library->getLastScan());
        $this->assertNull($library->getDiscoveryStatus());
        $this->assertInstanceOf(Uuid::class, $library->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $library->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $library->getUpdatedAt());
        $this->assertGreaterThanOrEqual($before, $library->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $library->getUpdatedAt());
    }

    public function testCreateWithSortOrder(): void
    {
        $library = Library::create(
            name: 'Podcasts',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Podcast,
            filesystemType: FilesystemType::Local,
            sortOrder: 5,
        );

        $this->assertSame(5, $library->getSortOrder());
        $this->assertSame(LibraryType::Podcast, $library->getType());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Library name cannot be empty.');

        Library::create(
            name: '  ',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }

    public function testCreateThrowsOnWhitespaceOnlyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Library::create(
            name: "\t\n",
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $scanTime = new \DateTimeImmutable('-1 hour');
        $id = Uuid::v4();
        $slug = new LibrarySlug('audiobooks');
        $path = new LibraryPath('/media/audiobooks');

        $library = Library::reconstitute(new LibraryState(
            id: $id,
            name: 'Audiobooks',
            slug: $slug,
            path: $path,
            type: LibraryType::Audiobook,
            filesystemType: FilesystemType::Local,
            sortOrder: 3,
            lastScan: $scanTime,
            discoveryStatus: null,
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertTrue($id->equals($library->getId()));
        $this->assertSame('Audiobooks', $library->getName());
        $this->assertSame('audiobooks', $library->getSlug()->toString());
        $this->assertSame('/media/audiobooks', $library->getPath()->toString());
        $this->assertSame(LibraryType::Audiobook, $library->getType());
        $this->assertSame(3, $library->getSortOrder());
        $this->assertEquals($scanTime, $library->getLastScan());
        $this->assertEquals($now, $library->getCreatedAt());
        $this->assertEquals($now, $library->getUpdatedAt());
    }

    public function testReconstituteWithNullLastScan(): void
    {
        $library = Library::reconstitute(new LibraryState(
            id: Uuid::v4(),
            name: 'Movies',
            slug: new LibrarySlug('movies'),
            path: new LibraryPath('/media/movies'),
            type: LibraryType::Movie,
            filesystemType: FilesystemType::Local,
            sortOrder: 0,
            lastScan: null,
            discoveryStatus: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->assertNull($library->getLastScan());
        $this->assertNull($library->getDiscoveryStatus());
    }

    public function testReconstituteRestoresDiscoveryStatus(): void
    {
        $now = new \DateTimeImmutable();

        $library = Library::reconstitute(new LibraryState(
            id: Uuid::v4(),
            name: 'Music',
            slug: new LibrarySlug('music'),
            path: new LibraryPath('/media/music'),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
            sortOrder: 0,
            lastScan: null,
            discoveryStatus: 'completed',
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame('completed', $library->getDiscoveryStatus());
    }

    public function testUpdateMetadataChangesName(): void
    {
        $library = Library::create(
            name: 'Old Name',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
        $beforeUpdate = $library->getUpdatedAt();

        $library->updateMetadata(name: 'New Name');

        $this->assertSame('New Name', $library->getName());
        $this->assertGreaterThanOrEqual($beforeUpdate, $library->getUpdatedAt());
    }

    public function testUpdateMetadataChangesSortOrder(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->updateMetadata(sortOrder: 10);

        $this->assertSame(10, $library->getSortOrder());
    }

    public function testUpdateMetadataUpdatesBothFields(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->updateMetadata(name: 'Updated', sortOrder: 7);

        $this->assertSame('Updated', $library->getName());
        $this->assertSame(7, $library->getSortOrder());
    }

    public function testUpdateMetadataDoesNotChangeNameWhenNull(): void
    {
        $library = Library::create(
            name: 'Original',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->updateMetadata(name: null);

        $this->assertSame('Original', $library->getName());
    }

    public function testUpdateMetadataDoesNotChangeSortOrderWhenNull(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->updateMetadata(sortOrder: null);

        $this->assertSame(0, $library->getSortOrder());
    }

    public function testUpdateMetadataNoArgsOnlyUpdatesTimestamp(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
        $before = $library->getUpdatedAt();

        $library->updateMetadata();

        $this->assertSame('Music', $library->getName());
        $this->assertSame(0, $library->getSortOrder());
        $this->assertGreaterThanOrEqual($before, $library->getUpdatedAt());
    }

    public function testUpdateMetadataThrowsOnEmptyName(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Library name cannot be empty.');

        $library->updateMetadata(name: '');
    }

    public function testMarkDiscoveryStartedTransitionsToScanning(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->markDiscoveryStarted();

        $this->assertSame('scanning', $library->getDiscoveryStatus());
    }

    public function testMarkDiscoveryCompletedSetsLastScanAndStatus(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->markDiscoveryCompleted();

        $this->assertSame('completed', $library->getDiscoveryStatus());
        $this->assertNotNull($library->getLastScan());
        $this->assertInstanceOf(\DateTimeImmutable::class, $library->getLastScan());
    }

    public function testMarkDiscoveryFailedTransitionsToFailed(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->markDiscoveryFailed();

        $this->assertSame('failed', $library->getDiscoveryStatus());
        $this->assertNull($library->getLastScan());
    }

    public function testFullScanLifecycle(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $this->assertNull($library->getDiscoveryStatus());

        $library->markDiscoveryStarted();
        $this->assertSame('scanning', $library->getDiscoveryStatus());

        $library->markDiscoveryCompleted();
        $this->assertSame('completed', $library->getDiscoveryStatus());
        $this->assertNotNull($library->getLastScan());
    }

    public function testFailedScanThenSuccessfulScan(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $library->markDiscoveryStarted();
        $library->markDiscoveryFailed();
        $this->assertSame('failed', $library->getDiscoveryStatus());
        $this->assertNull($library->getLastScan());

        $library->markDiscoveryStarted();
        $library->markDiscoveryCompleted();
        $this->assertSame('completed', $library->getDiscoveryStatus());
        $this->assertNotNull($library->getLastScan());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
            sortOrder: 2,
        );

        $this->assertInstanceOf(Uuid::class, $library->getId());
        $this->assertInstanceOf(LibrarySlug::class, $library->getSlug());
        $this->assertInstanceOf(LibraryPath::class, $library->getPath());
        $this->assertInstanceOf(LibraryType::class, $library->getType());
        $this->assertIsString($library->getName());
        $this->assertIsInt($library->getSortOrder());
        $this->assertInstanceOf(\DateTimeImmutable::class, $library->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $library->getUpdatedAt());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Library::create('Lib A', $this->validSlug(), $this->validPath(), LibraryType::Music, FilesystemType::Local);
        $b = Library::create('Lib B', new LibrarySlug('lib-b'), $this->validPath(), LibraryType::Movie, FilesystemType::Local);

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testAllLibraryTypesCanBeUsed(): void
    {
        $slugMap = [
            'music' => 'music',
            'podcast' => 'podcast',
            'audiobook' => 'audiobook',
            'movie' => 'movie',
            'tv_show' => 'tv-show',
        ];

        foreach (LibraryType::cases() as $type) {
            $library = Library::create(
                name: "Test {$type->value}",
                slug: new LibrarySlug($slugMap[$type->value]),
                path: $this->validPath(),
                type: $type,
            filesystemType: FilesystemType::Local,
            );

            $this->assertSame($type, $library->getType());
        }
    }

    public function testGetStateReturnsLibraryState(): void
    {
        $library = Library::create(
            name: 'Music',
            slug: $this->validSlug(),
            path: $this->validPath(),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );

        $state = $library->getState();
        $this->assertInstanceOf(LibraryState::class, $state);
        $this->assertSame('Music', $state->name);
        $this->assertSame(0, $state->sortOrder);
    }
}
