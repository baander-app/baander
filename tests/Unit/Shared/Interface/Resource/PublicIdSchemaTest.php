<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Resource;

use App\Activity\Interface\Resource as Activity;
use App\Auth\Interface\Resource as Auth;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Interface\Resource\SongResource;
use App\Discovery\Interface\Request as DiscoveryRequest;
use App\Discovery\Interface\Resource as Discovery;
use App\Party\Interface\Resource as Party;
use App\Lyrics\Interface\Request\ApplyLyricsRequest;
use App\Playlist\Interface\Request\AddSongRequest;
use App\Playlist\Interface\Request\ReorderSongsRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Interface\Resource as Transcode;
use OpenApi\Attributes\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicIdSchemaTest extends TestCase
{
    /** @param class-string $resource */
    #[DataProvider('publicIdentifiers')]
    public function test_public_identifier_attributes_match_runtime_identifiers(string $resource, string $field, bool $nullable): void
    {
        $schema = (new ReflectionClass($resource))->getAttributes(Schema::class)[0]->newInstance();
        foreach ($schema->properties as $property) {
            if ($property->property !== $field) {
                continue;
            }

            self::assertSame('string', $property->type);
            self::assertNotSame('uuid', $property->format);
            self::assertSame(21, $property->minLength);
            self::assertSame(21, $property->maxLength);
            self::assertSame('^[0-9a-zA-Z_-]{21}$', $property->pattern);
            self::assertSame($nullable, $property->nullable === true);
            self::assertMatchesRegularExpression('~' . $property->pattern . '~', (new PublicId())->toString());
            self::assertMatchesRegularExpression('~' . $property->pattern . '~', PublicId::fromString('0123456789abABCD_-xyz')->toString());
            foreach (['', str_repeat('a', 20), str_repeat('a', 22), str_repeat('!', 21), '0199e4d5-3891-7000-8000-000000000001'] as $invalid) {
                self::assertDoesNotMatchRegularExpression('~' . $property->pattern . '~', $invalid);
            }

            return;
        }

        self::fail($resource . ' has no ' . $field . ' schema.');
    }

    public function test_song_album_identifier_uses_the_serialized_public_id(): void
    {
        $schema = (new ReflectionClass(SongResource::class))->getAttributes(Schema::class)[0]->newInstance();
        $properties = array_column($schema->properties, null, 'property');
        self::assertSame('string', $properties['albumId']->type);
        self::assertNotSame('uuid', $properties['albumId']->format);

        $albumUuid = new Uuid();
        foreach ([new PublicId()] as $albumPublicId) {
            $song = Song::reconstitute(new SongState(
                id: new Uuid(), publicId: new PublicId(), album: $albumUuid,
                title: 'Song', path: '/music/song.flac', size: 1, mimeType: 'audio/flac',
                length: null, lyrics: null, track: null, disc: null, year: null,
                comment: null, hash: null, bitrate: null, sampleRate: null, channels: null,
                codec: null, explicit: false, albumPublicId: $albumPublicId,
            ));
            self::assertSame($albumPublicId->toString(), SongResource::from($song)['albumId']);
        }
    }

    public function test_request_identifier_examples_match_their_runtime_types(): void
    {
        $lyrics = (new ReflectionClass(ApplyLyricsRequest::class))->getAttributes(Schema::class)[0]->newInstance();
        $example = $lyrics->properties[0]->example;
        self::assertSame($example, PublicId::fromString($example)->toString());

        $add = (new ReflectionClass(AddSongRequest::class))->getAttributes(Schema::class)[0]->newInstance();
        self::assertNotSame('uuid', $add->properties[0]->format);
        self::assertSame($add->properties[0]->example, PublicId::fromString($add->properties[0]->example)->toString());
        $reorder = (new ReflectionClass(ReorderSongsRequest::class))->getAttributes(Schema::class)[0]->newInstance();
        self::assertNotSame('uuid', $reorder->properties[0]->items->format);
        foreach ($reorder->properties[0]->example as $uuid) {
            self::assertSame($uuid, PublicId::fromString($uuid)->toString());
        }
    }

    /** @return iterable<string, array{class-string, string, bool}> */
    public static function publicIdentifiers(): iterable
    {
        foreach ([
            Activity\ActivityResource::class,
            Activity\RecentItemResource::class,
            Auth\AdminUserResource::class,
            Auth\ClientResource::class,
            Auth\UserResource::class,
            Discovery\ServerInstanceResource::class,
            Discovery\PairingSessionResource::class,
            Party\PartyMemberResource::class,
            Party\PartySessionResource::class,
            Transcode\TranscodeJobResource::class,
            Transcode\TranscodeMetricsResource::class,
            Transcode\TranscodeSessionResource::class,
        ] as $resource) {
            yield $resource => [$resource, 'publicId', false];
        }
        yield 'recent song' => [Activity\RecentItemResource::class, 'songPublicId', true];
        yield 'recent album' => [Activity\RecentItemResource::class, 'albumPublicId', true];
        yield 'pairing server' => [Discovery\PairingSessionResource::class, 'serverPublicId', false];
        yield 'create pairing server' => [DiscoveryRequest\CreatePairingCodeRequest::class, 'serverPublicId', false];
        yield 'complete pairing server' => [DiscoveryRequest\CompletePairingRequest::class, 'serverPublicId', false];
        yield 'apply lyrics song' => [ApplyLyricsRequest::class, 'songPublicId', false];
    }
}
