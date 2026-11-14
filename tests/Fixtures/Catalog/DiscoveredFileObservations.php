<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
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

    private function audioProbeStub(): AudioProbePortInterface
    {
        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturn(new AudioProbeResult('flac', 0, 'flac', 2, 44100, 320000));

        return $probe;
    }

    /** Resolves each identity to an artist of its own, the same artist each time the same key comes back. */
    private function artistIdentitiesStub(): ArtistIdentityResolverInterface
    {
        $artists = [];
        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (array $identities) use (&$artists): array {
            $resolved = [];
            foreach ($identities as $identity) {
                $resolved[$identity->key()] = $artists[$identity->key()] ??= Uuid::v7();
            }

            return $resolved;
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

        return $media;
    }
}
