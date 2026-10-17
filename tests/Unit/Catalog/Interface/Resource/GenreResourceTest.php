<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\Model\GenreState;
use App\Catalog\Interface\Resource\GenreResource;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GenreResourceTest extends TestCase
{
    public function testRootGenreSerializesTheCompleteContractWithoutParent(): void
    {
        $genre = Genre::create('Rock', 'rock');

        self::assertSame([
            'publicId' => $genre->getPublicId()->toString(),
            'name' => 'Rock',
            'slug' => 'rock',
            'parentId' => null,
            'mbid' => null,
        ], GenreResource::from($genre));
    }

    public function testChildGenreSerializesItsParentPublicId(): void
    {
        $parent = Genre::create('Rock', 'rock');
        $mbid = '0192f3ca-c632-783c-88f6-c53f42d5a3c1';
        $genre = Genre::create('Alternative Rock', 'alternative-rock', parent: $parent->getId(), mbid: $mbid);
        $genre->setParent($parent);

        self::assertSame([
            'publicId' => $genre->getPublicId()->toString(),
            'name' => 'Alternative Rock',
            'slug' => 'alternative-rock',
            'parentId' => $parent->getPublicId()->toString(),
            'mbid' => $mbid,
        ], GenreResource::from($genre));
    }

    public function testReconstitutedGenreAndCollectionsRetainTheirHierarchy(): void
    {
        $parent = Genre::create('Electronic', 'electronic');
        $now = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $childId = Uuid::generate();
        $child = Genre::reconstitute(new GenreState(
            publicId: new \App\Shared\Domain\Model\PublicId(),
            id: $childId,
            name: 'Ambient',
            slug: 'ambient',
            mbid: null,
            parent: $parent->getId(),
            parentPublicId: $parent->getPublicId(),
            createdAt: $now,
            updatedAt: $now,
        ));

        $resources = GenreResource::collection([$parent, $child]);
        self::assertSame(null, $resources[0]['parentId']);
        self::assertSame($parent->getPublicId()->toString(), $resources[1]['parentId']);
        self::assertSame($child->getPublicId()->toString(), $resources[1]['publicId']);
        $child->setParent(null);
        self::assertNull(GenreResource::from($child)['parentId']);
    }
}
