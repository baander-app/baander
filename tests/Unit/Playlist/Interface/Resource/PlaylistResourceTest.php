<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Resource;

use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Playlist\Interface\Resource\PlaylistResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** The playlist resource names the owner and the collaborators by public id; no user UUID leaves the API. */
final class PlaylistResourceTest extends TestCase
{
    public function testFromCarriesThePublicIdsOfTheOwnerAndTheCollaborators(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('p', 21));
        $owner = PublicId::fromString(str_repeat('o', 21));
        $first = PublicId::fromString(str_repeat('a', 21));
        $second = PublicId::fromString(str_repeat('b', 21));
        $createdAt = new DateTimeImmutable('2025-06-01 12:00:00');

        $result = PlaylistResource::from($this->view(
            id: $id,
            publicId: $publicId,
            ownerPublicId: $owner,
            collaboratorPublicIds: [$first, $second],
            name: 'My Playlist',
            description: 'A great playlist',
            isPublic: true,
            isCollaborative: true,
            songCount: 3,
            createdAt: $createdAt,
        ));

        $this->assertSame([
            'publicId' => $publicId->toString(),
            'ownerPublicId' => $owner->toString(),
            'collaboratorPublicIds' => [$first->toString(), $second->toString()],
            'name' => 'My Playlist',
            'description' => 'A great playlist',
            'isPublic' => true,
            'isCollaborative' => true,
            'isSmart' => false,
            'songCount' => 3,
            'createdAt' => '2025-06-01T12:00:00+00:00',
        ], $result);
        $this->assertArrayNotHasKey('userId', $result);
    }

    public function testFromWithSmartPlaylistAndNoCollaborators(): void
    {
        $result = PlaylistResource::from($this->view(
            name: 'Smart Mix',
            description: null,
            isSmart: true,
            smartRules: [['field' => 'genre', 'operator' => 'equals', 'value' => 'jazz']],
        ));

        $this->assertTrue($result['isSmart']);
        $this->assertSame('Smart Mix', $result['name']);
        $this->assertNull($result['description']);
        $this->assertSame([], $result['collaboratorPublicIds']);
        $this->assertSame(0, $result['songCount']);
    }

    public function testCollectionTransformsMultiplePlaylists(): void
    {
        $result = PlaylistResource::collection([
            $this->view(name: 'First', isPublic: false),
            $this->view(name: 'Second', isPublic: true),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('First', $result[0]['name']);
        $this->assertSame('Second', $result[1]['name']);
        $this->assertFalse($result[0]['isPublic']);
        $this->assertTrue($result[1]['isPublic']);
    }

    /**
     * @param list<PublicId> $collaboratorPublicIds
     * @param array<array-key, mixed> $smartRules
     */
    private function view(
        ?Uuid $id = null,
        ?PublicId $publicId = null,
        ?PublicId $ownerPublicId = null,
        array $collaboratorPublicIds = [],
        string $name = 'Playlist',
        ?string $description = null,
        bool $isPublic = false,
        bool $isCollaborative = false,
        bool $isSmart = false,
        array $smartRules = [],
        int $songCount = 0,
        ?DateTimeImmutable $createdAt = null,
    ): PlaylistReadView {
        $createdAt ??= new DateTimeImmutable();

        return new PlaylistReadView(
            id: $id ?? Uuid::v4(),
            publicId: $publicId ?? new PublicId(),
            ownerPublicId: $ownerPublicId ?? new PublicId(),
            collaboratorPublicIds: $collaboratorPublicIds,
            name: $name,
            description: $description,
            isPublic: $isPublic,
            isCollaborative: $isCollaborative,
            isSmart: $isSmart,
            smartRules: $smartRules,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            songCount: $songCount,
        );
    }
}
