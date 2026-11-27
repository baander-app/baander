<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\ArtistResolution;
use App\Catalog\Application\Port\IdentifiedArtist;
use App\Catalog\Application\Service\TvLibraryIngest;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use PHPUnit\Framework\MockObject\Stub;

trait DiscoveredFileObservations
{
    /** @var array<string, string> */
    private array $availableHashes = [];

    private function unusedTvIngest(): TvLibraryIngest
    {
        return new TvLibraryIngest(
            $this->createStub(TvCatalogRepositoryInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $this->createStub(LibraryMediaFilesInterface::class),
            new EpisodeNameParser(),
        );
    }

    private function audioProbeStub(): AudioProbePortInterface
    {
        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturn(new AudioProbeResult('flac', 0, 'flac', 2, 44100, 320000));

        return $probe;
    }

    /**
     * Resolves each identity to an artist of its own, the same artist each time the same key comes
     * back, and reports each artist it creates with a MusicBrainz ID.
     */
    private function artistIdentitiesStub(): ArtistIdentityResolverInterface
    {
        $artists = [];
        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolveReporting')->willReturnCallback(static function (array $identities) use (&$artists): ArtistResolution {
            $resolved = [];
            $identified = [];
            foreach ($identities as $identity) {
                $key = $identity->key();
                if (!isset($artists[$key]) && $identity->musicbrainzId !== null) {
                    $identified[] = new IdentifiedArtist($artists[$key] = Uuid::v7(), $identity->musicbrainzId->toString());
                }
                $resolved[$key] = $artists[$key] ??= Uuid::v7();
            }

            return new ArtistResolution($resolved, $identified);
        });

        return $resolver;
    }

    private function observedFile(DiscoveredFile $file): DiscoveredFile
    {
        $this->availableHashes[$file->absolutePath] = $file->hash;

        return $file;
    }

    /** @template T of LibraryMediaFilesInterface&Stub
     * @param T $media
     * @return T
     */
    private function observedMediaFiles(LibraryMediaFilesInterface&Stub $media): LibraryMediaFilesInterface&Stub
    {
        foreach (['findAvailableHashesByPaths', 'lockAvailableHashesByPathsForImport'] as $method) {
            $media->method($method)->willReturnCallback(fn (Uuid $libraryId, array $paths): array => array_intersect_key($this->availableHashes, array_fill_keys($paths, true)));
        }
        // Sidecars are read as the test's files hold them; confinement is LibraryMediaFiles' own.
        $media->method('readSidecar')->willReturnCallback(
            static fn (Uuid $libraryId, string $path, int $maxBytes): ?string => is_file($path) ? (string) file_get_contents($path) : null,
        );

        return $media;
    }
}
