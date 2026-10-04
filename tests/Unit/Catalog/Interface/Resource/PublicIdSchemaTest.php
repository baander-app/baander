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

    /** @return iterable<string, array{class-string}> */
    public static function resources(): iterable
    {
        yield 'album' => [AlbumResource::class];
        yield 'artist' => [ArtistResource::class];
        yield 'song' => [SongResource::class];
        yield 'movie' => [MovieResource::class];
        yield 'video' => [VideoResource::class];
    }
}
