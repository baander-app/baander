<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Service\StreamService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SongTechnicalMetadataPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testTechnicalMetadataSurvivesBothRepositoryHydrationsAndPlaybackProjection(): void
    {
        $library = new LibraryEntity('Audio source metadata', 'audio-source-' . bin2hex(random_bytes(6)), '/audio-source', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Audio source', 'album');
        $this->manager->persist($library);
        $this->manager->persist($album);
        $this->manager->flush();
        $songs = new SongRepository($this->manager, new CursorPaginator(), new CursorCodec(new JsonEncoder()));
        $song = Song::create($album->getId(), 'AAC', '/audio-source/source.aac', 100,
            'audio/aac', hash: 'source-hash', codec: 'aac', containerFormat: 'aac', streamIndex: 3);
        $unknown = Song::create($album->getId(), 'Unprobed', '/audio-source/unknown.aac', 100, 'audio/aac');
        $songs->save($song);
        $songs->save($unknown);
        $this->manager->clear();

        $loaded = $songs->findByUuid($song->getId());
        self::assertNotNull($loaded);
        self::assertSame('aac', $loaded->getContainerFormat());
        self::assertSame(3, $loaded->getStreamIndex());
        $visible = $songs->findVisibleByUuids([$song->getId(), $unknown->getId()], LibraryReadScope::unrestricted());
        self::assertSame('aac', $visible[$song->getId()->toString()]->getContainerFormat());
        self::assertSame(3, $visible[$song->getId()->toString()]->getStreamIndex());
        self::assertNull($visible[$unknown->getId()->toString()]->getContainerFormat());
        self::assertNull($visible[$unknown->getId()->toString()]->getStreamIndex());
        $metadata = new StreamService($songs, $this->createStub(LibraryMediaFileResolverInterface::class))->getTrackMetadata($song->getPublicId());
        self::assertSame('source-hash', $metadata->sourceHash);
        self::assertSame('aac', $metadata->containerFormat);
        self::assertSame(3, $metadata->streamIndex);
    }
}
