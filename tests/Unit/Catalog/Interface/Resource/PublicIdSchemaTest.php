<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Interface\Resource\AlbumResource;
use App\Catalog\Interface\Resource\ArtistResource;
use App\Catalog\Interface\Resource\MovieResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Catalog\Interface\Resource\VideoResource;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Attributes\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicIdSchemaTest extends TestCase
{
    /** @param class-string $resource */
    #[DataProvider('resources')]
    public function testPublicIdentifierSchemaMatchesGeneratedIdentifiers(string $resource): void
    {
        $schema = (new ReflectionClass($resource))->getAttributes(Schema::class)[0]->newInstance();
        foreach ($schema->properties as $property) {
            if ($property->property !== 'publicId') {
                continue;
            }

            self::assertSame('string', $property->type);
            self::assertNotSame('uuid', $property->format);
            self::assertSame(21, $property->minLength);
            self::assertSame(21, $property->maxLength);
            self::assertSame('^[0-9a-zA-Z_-]{21}$', $property->pattern);
            self::assertMatchesRegularExpression('~' . $property->pattern . '~', (new PublicId())->toString());
            return;
        }

        self::fail($resource . ' has no public identifier schema.');
    }

    /** @param class-string $resource */
    #[DataProvider('relationships')]
    public function testRelationshipSchemasRequirePublicIdentifiers(string $resource, string $field, bool $array): void
    {
        $schema = (new ReflectionClass($resource))->getAttributes(Schema::class)[0]->newInstance();
        foreach ($schema->properties as $property) {
            if ($property->property !== $field) {
                continue;
            }
            $identity = $array ? $property->items : $property;
            self::assertSame('string', $identity->type);
            self::assertSame(21, $identity->minLength);
            self::assertSame(21, $identity->maxLength);
            self::assertSame('^[0-9a-zA-Z_-]{21}$', $identity->pattern);
            return;
        }
        self::fail('Missing relationship schema: ' . $field);
    }

    /** @return iterable<string, array{class-string, string, bool}> */
    public static function relationships(): iterable
    {
        yield 'song album' => [SongResource::class, 'albumId', false];
        yield 'genre parent' => [\App\Catalog\Interface\Resource\GenreResource::class, 'parentId', false];
        yield 'movie videos' => [MovieResource::class, 'videoIds', true];
        yield 'duplicate albums' => [\App\Catalog\Interface\Resource\DuplicateGroupResource::class, 'albumIds', true];
        yield 'image album' => [\App\Media\Interface\Resource\ImageResource::class, 'albumId', false];
        yield 'image artist' => [\App\Media\Interface\Resource\ImageResource::class, 'artistId', false];
        yield 'image playlist' => [\App\Media\Interface\Resource\ImageResource::class, 'playlistId', false];
        yield 'library access' => [\App\Library\Interface\Resource\LibraryAccessResource::class, 'libraryId', false];
    }

    /** @return iterable<string, array{class-string}> */
    public static function resources(): iterable
    {
        yield 'album' => [AlbumResource::class];
        yield 'artist' => [ArtistResource::class];
        yield 'song' => [SongResource::class];
        yield 'movie' => [MovieResource::class];
        yield 'video' => [VideoResource::class];
        yield 'genre' => [\App\Catalog\Interface\Resource\GenreResource::class];
        yield 'library' => [\App\Library\Interface\Resource\LibraryResource::class];
    }
}
