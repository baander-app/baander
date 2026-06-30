<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\Model\GenreState;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GenreTest extends TestCase
{
    public function testCreateWithRequiredFields(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $this->assertSame('Rock', $genre->getName());
        $this->assertSame('rock', $genre->getSlug());
        $this->assertNull($genre->getMbid());
        $this->assertNull($genre->getParent());
    }

    public function testCreateWithOptionalMbid(): void
    {
        $mbid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $genre = Genre::create('Jazz', 'jazz', mbid: $mbid);

        $this->assertSame('Jazz', $genre->getName());
        $this->assertSame('jazz', $genre->getSlug());
        $this->assertSame($mbid, $genre->getMbid());
    }

    public function testCreateWithParent(): void
    {
        $parent = Genre::create('Electronic', 'electronic');
        $child = Genre::create('Techno', 'techno', parent: $parent->getId());

        $this->assertSame($parent->getId(), $child->getParent());
    }

    public function testCreateNormalizesNameWhitespace(): void
    {
        $genre = Genre::create('  Jazz  ', 'jazz');

        $this->assertSame('Jazz', $genre->getName());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre name cannot be empty.');

        Genre::create('', 'empty-genre');
    }

    public function testCreateThrowsOnWhitespaceOnlyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre name cannot be empty.');

        Genre::create('   ', 'whitespace-genre');
    }

    public function testCreateThrowsOnInvalidSlugWithUppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "Rock" is invalid');

        Genre::create('Rock', 'Rock');
    }

    public function testCreateThrowsOnInvalidSlugWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "rock roll" is invalid');

        Genre::create('Rock Roll', 'rock roll');
    }

    public function testCreateThrowsOnInvalidSlugWithSpecialCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "rock&roll" is invalid');

        Genre::create('Rock & Roll', 'rock&roll');
    }

    public function testCreateThrowsOnInvalidSlugStartingWithHyphen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "-rock" is invalid');

        Genre::create('Rock', '-rock');
    }

    public function testCreateThrowsOnInvalidSlugEndingWithHyphen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "rock-" is invalid');

        Genre::create('Rock', 'rock-');
    }

    public function testCreateThrowsOnInvalidSlugWithConsecutiveHyphens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "rock--roll" is invalid');

        Genre::create('Rock Roll', 'rock--roll');
    }

    public function testCreateAcceptsValidSlugWithHyphens(): void
    {
        $genre = Genre::create('Heavy Metal', 'heavy-metal');

        $this->assertSame('heavy-metal', $genre->getSlug());
    }

    public function testCreateAcceptsValidSlugWithNumbers(): void
    {
        $genre = Genre::create('2 Step', '2-step');

        $this->assertSame('2-step', $genre->getSlug());
    }

    public function testCreateAcceptsSingleWordSlug(): void
    {
        $genre = Genre::create('Pop', 'pop');

        $this->assertSame('pop', $genre->getSlug());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $mbid = 'b1b2b3b4-b5b6-7890-abcd-ef1234567890';
        $genre = Genre::reconstitute(new GenreState(
            id: Uuid::v4(),
            name: 'Blues',
            slug: 'blues',
            mbid: $mbid,
            parent: Uuid::v4(),
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame('Blues', $genre->getName());
        $this->assertSame('blues', $genre->getSlug());
        $this->assertSame($mbid, $genre->getMbid());
        $this->assertNotNull($genre->getParent());
    }

    public function testReconstituteWithNullParent(): void
    {
        $now = new \DateTimeImmutable();
        $genre = Genre::reconstitute(new GenreState(
            id: Uuid::v4(),
            name: 'Classical',
            slug: 'classical',
            mbid: null,
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertNull($genre->getParent());
    }

    public function testUpdateNameAndSlug(): void
    {
        $genre = Genre::create('Old Name', 'old-name');
        $genre->update('New Name', 'new-name');

        $this->assertSame('New Name', $genre->getName());
        $this->assertSame('new-name', $genre->getSlug());
    }

    public function testUpdateNormalizesName(): void
    {
        $genre = Genre::create('Old', 'old');
        $genre->update('  New Name  ', 'new-name');

        $this->assertSame('New Name', $genre->getName());
    }

    public function testUpdateThrowsOnEmptyName(): void
    {
        $genre = Genre::create('Old', 'old');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre name cannot be empty.');

        $genre->update('', 'new-slug');
    }

    public function testUpdateThrowsOnInvalidSlug(): void
    {
        $genre = Genre::create('Old', 'old');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Genre slug "Invalid Slug" is invalid');

        $genre->update('New Name', 'Invalid Slug');
    }

    public function testUpdateSetsUpdatedAt(): void
    {
        $genre = Genre::create('Old', 'old');
        $before = $genre->getUpdatedAt();

        $genre->update('New Name', 'new-name');

        $this->assertNotEquals($before, $genre->getUpdatedAt());
    }

    public function testSetParentWithGenreObject(): void
    {
        $parent = Genre::create('Electronic', 'electronic');
        $child = Genre::create('House', 'house');

        $child->setParent($parent);

        $this->assertEquals($parent->getId(), $child->getParent());
    }

    public function testSetParentWithNullClearsParent(): void
    {
        $parent = Genre::create('Electronic', 'electronic');
        $child = Genre::create('House', 'house', parent: $parent->getId());

        $this->assertNotNull($child->getParent());

        $child->setParent(null);

        $this->assertNull($child->getParent());
    }

    public function testSetParentSetsUpdatedAt(): void
    {
        $parent = Genre::create('Electronic', 'electronic');
        $child = Genre::create('House', 'house');
        $before = $child->getUpdatedAt();

        $child->setParent($parent);

        $this->assertNotEquals($before, $child->getUpdatedAt());
    }

    public function testSetParentId(): void
    {
        $parentId = Uuid::v4();
        $genre = Genre::create('Test', 'test');

        $genre->setParentId($parentId);

        $this->assertEquals($parentId, $genre->getParent());
    }

    public function testSetParentIdWithNull(): void
    {
        $genre = Genre::create('Test', 'test', parent: Uuid::v4());
        $genre->setParentId(null);

        $this->assertNull($genre->getParent());
    }

    public function testSetParentIdSetsUpdatedAt(): void
    {
        $genre = Genre::create('Test', 'test');
        $before = $genre->getUpdatedAt();

        $genre->setParentId(Uuid::v4());

        $this->assertNotEquals($before, $genre->getUpdatedAt());
    }

    public function testUpdateMbid(): void
    {
        $genre = Genre::create('Rock', 'rock');
        $mbid = 'c1c2c3c4-c5c6-7890-abcd-ef1234567890';

        $genre->updateMbid($mbid);

        $this->assertSame($mbid, $genre->getMbid());
    }

    public function testUpdateMbidToNull(): void
    {
        $genre = Genre::create('Rock', 'rock', mbid: 'd1d2d3d4-d5d6-7890-abcd-ef1234567890');
        $genre->updateMbid(null);

        $this->assertNull($genre->getMbid());
    }

    public function testUpdateMbidSetsUpdatedAt(): void
    {
        $genre = Genre::create('Rock', 'rock');
        $before = $genre->getUpdatedAt();

        $genre->updateMbid('e1e2e3e4-e5e6-7890-abcd-ef1234567890');

        $this->assertNotEquals($before, $genre->getUpdatedAt());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $this->assertInstanceOf(Uuid::class, $genre->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $genre->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $genre->getUpdatedAt());
    }

    public function testCreatedAtAndUpdatedAtAreCloseOnCreation(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $diff = $genre->getUpdatedAt()->getTimestamp() - $genre->getCreatedAt()->getTimestamp();
        $this->assertSame(0, $diff, 'createdAt and updatedAt should have the same second on creation.');
    }

    public function testSetParentThrowsOnSelfReference(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A genre cannot be its own parent.');

        $genre->setParent($genre);
    }

    public function testSetParentIdThrowsOnSelfReference(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A genre cannot be its own parent.');

        $genre->setParentId($genre->getId());
    }

    public function testCreateThrowsOnInvalidMbidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid MusicBrainz ID format');

        Genre::create('Rock', 'rock', mbid: 'not-a-valid-mbid');
    }

    public function testUpdateMbidThrowsOnInvalidFormat(): void
    {
        $genre = Genre::create('Rock', 'rock');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid MusicBrainz ID format');

        $genre->updateMbid('invalid-mbid');
    }

    public function testUpdateMbidAcceptsNullWithoutValidation(): void
    {
        $genre = Genre::create('Rock', 'rock', mbid: 'f1f2f3f4-f5f6-7890-abcd-ef1234567890');
        $genre->updateMbid(null);

        $this->assertNull($genre->getMbid());
    }
}
