<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Resource;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Model\LibraryState;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Resource\LibraryResource;
use PHPUnit\Framework\TestCase;

final class LibraryResourceTest extends TestCase
{
    private function createLibrary(
        ?string $name = null,
        ?LibraryType $type = null,
        ?\DateTimeImmutable $lastScan = null,
    ): Library {
        $id = \App\Shared\Domain\Model\Uuid::v4();
        $now = new \DateTimeImmutable('2024-06-15T12:00:00+00:00');

        return Library::reconstitute(new LibraryState(
            id: $id,
            name: $name ?? 'My Music',
            slug: new LibrarySlug('my-music'),
            path: new LibraryPath('/media/music'),
            type: $type ?? LibraryType::Music,
            filesystemType: FilesystemType::Local,
            sortOrder: 2,
            lastScan: $lastScan,
            discoveryStatus: null,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public function testFromTransformsLibraryToArray(): void
    {
        $library = $this->createLibrary();

        $result = LibraryResource::from($library);

        $this->assertIsArray($result);
        $this->assertSame($library->getId()->toString(), $result['id']);
        $this->assertSame('My Music', $result['name']);
        $this->assertSame('my-music', $result['slug']);
        $this->assertSame('/media/music', $result['path']);
        $this->assertSame('music', $result['type']);
        $this->assertSame(2, $result['sortOrder']);
    }

    public function testFromIncludesTimestampsInAtomFormat(): void
    {
        $library = $this->createLibrary();

        $result = LibraryResource::from($library);

        $this->assertArrayHasKey('createdAt', $result);
        $this->assertArrayHasKey('updatedAt', $result);
        $this->assertStringContainsString('T', $result['createdAt']);
        $this->assertStringContainsString('+', $result['createdAt']);
    }

    public function testFromWithNullLastScan(): void
    {
        $library = $this->createLibrary(lastScan: null);

        $result = LibraryResource::from($library);

        $this->assertNull($result['lastScan']);
    }

    public function testFromWithLastScanSet(): void
    {
        $scanTime = new \DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $library = $this->createLibrary(lastScan: $scanTime);

        $result = LibraryResource::from($library);

        $this->assertSame('2024-06-15T10:00:00+00:00', $result['lastScan']);
    }

    public function testFromWithScanStatus(): void
    {
        $library = $this->createLibrary();
        $library->markDiscoveryCompleted();

        $result = LibraryResource::from($library);

        $this->assertSame('completed', $result['scanStatus']);
    }

    public function testFromWithDifferentType(): void
    {
        $library = $this->createLibrary(type: LibraryType::Podcast);

        $result = LibraryResource::from($library);

        $this->assertSame('podcast', $result['type']);
    }

    public function testFromReturnsAllExpectedKeys(): void
    {
        $library = $this->createLibrary();

        $result = LibraryResource::from($library);

        $expectedKeys = [
            'id', 'name', 'slug', 'path', 'type', 'filesystemType',
            'sortOrder', 'lastScan', 'scanStatus',
            'createdAt', 'updatedAt',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function testFromHasExactlyTenKeys(): void
    {
        $library = $this->createLibrary();

        $result = LibraryResource::from($library);

        $this->assertCount(11, $result);
    }

    public function testCollectionTransformsMultipleLibraries(): void
    {
        $libraries = [
            $this->createLibrary(name: 'Music'),
            $this->createLibrary(name: 'Podcasts', type: LibraryType::Podcast),
        ];

        $result = LibraryResource::collection($libraries);

        $this->assertCount(2, $result);
        $this->assertSame('Music', $result[0]['name']);
        $this->assertSame('Podcasts', $result[1]['name']);
        $this->assertSame('podcast', $result[1]['type']);
    }

    public function testCollectionReturnsEmptyArrayForEmptyInput(): void
    {
        $result = LibraryResource::collection([]);

        $this->assertSame([], $result);
    }
}
