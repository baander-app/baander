<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistMusicbrainzIdAssigner;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\MusicbrainzIdAssignment;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Psr\Log\LoggerInterface;

final class ArtistService implements ArtistPortInterface
{
    public function __construct(
        private readonly ArtistRepositoryInterface $artistRepository,
        private readonly TransactionPortInterface $transaction,
        private readonly ArtistMusicbrainzIdAssigner $musicbrainzIds,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Artist
    {
        return $this->artistRepository->findVisibleByPublicId($publicId, $scope);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Artist
    {
        return $this->artistRepository->findVisibleByUuid($uuid, $scope);
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        return $this->artistRepository->searchVisible($options, $scope);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return $this->artistRepository->countVisible($scope);
    }

    public function findByPublicId(PublicId $publicId): ?Artist
    {
        return $this->artistRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Artist
    {
        return $this->artistRepository->findByUuid($uuid);
    }

    public function findFreshByUuid(Uuid $uuid): ?Artist
    {
        return $this->artistRepository->findFreshByUuid($uuid);
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Artist
    {
        return $this->artistRepository->findByMbid($mbid);
    }

    public function findByName(string $name): ?Artist
    {
        return $this->artistRepository->findByName($name);
    }

    public function assignMusicbrainzId(Uuid $artistId, string $musicbrainzId, string $matchedName): bool
    {
        $id = MusicbrainzId::tryFromString($musicbrainzId);
        $outcome = $id === null
            ? MusicbrainzIdAssignment::MalformedId
            : $this->transaction->run(fn (): MusicbrainzIdAssignment => $this->musicbrainzIds->assign($artistId, $id, $matchedName));

        if ($outcome !== MusicbrainzIdAssignment::Assigned) {
            $this->logger->info('Artist MusicBrainz ID not assigned', [
                'artist_id' => $artistId->toString(),
                'mbid' => $musicbrainzId,
                'matched_name' => $matchedName,
                'reason' => $outcome->value,
            ]);
        }

        return $outcome === MusicbrainzIdAssignment::Assigned;
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->artistRepository->search($options);
    }

    public function count(): int
    {
        return $this->artistRepository->count();
    }

    public function save(Artist $artist): void
    {
        $this->artistRepository->save($artist);
    }

    public function persist(Artist $artist): void
    {
        $this->artistRepository->persist($artist);
    }

    public function flush(): void
    {
        $this->artistRepository->flush();
    }

    public function delete(Artist $artist): void
    {
        $this->artistRepository->delete($artist);
    }

    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): bool
    {
        return $this->artistRepository->addSongToArtist($artistId, $songId, $role);
    }

    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): bool
    {
        return $this->artistRepository->removeSongFromArtist($artistId, $songId);
    }

    public function songCreditRoles(Uuid $artistId, Uuid $songId): array
    {
        return $this->artistRepository->songCreditRoles($artistId, $songId);
    }

    public function updateSongRole(Uuid $artistId, Uuid $songId, ?string $currentRole, string $role): bool
    {
        return $this->artistRepository->updateSongRole($artistId, $songId, $currentRole, $role);
    }

    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): bool
    {
        return $this->artistRepository->addAlbumToArtist($artistId, $albumId, $role);
    }

    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): bool
    {
        return $this->artistRepository->removeAlbumFromArtist($artistId, $albumId);
    }

    public function albumCreditRoles(Uuid $artistId, Uuid $albumId): array
    {
        return $this->artistRepository->albumCreditRoles($artistId, $albumId);
    }

    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, ?string $currentRole, string $role): bool
    {
        return $this->artistRepository->updateAlbumRole($artistId, $albumId, $currentRole, $role);
    }
}
