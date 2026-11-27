<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;
use App\Transcode\Application\Exception\UnsupportedMediaContainerException;
use App\Transcode\Application\Port\VideoProbePortInterface;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @phpstan-type PreparedVideo array{file: DiscoveredFile, video: Video, isNew: bool, probe: array<string, mixed>|null}
 * @phpstan-type VideoImportFailure array{path: string, error: string}
 * @phpstan-type VideoBatchImportResult array{writtenPaths: list<string>|null, failures: list<VideoImportFailure>}
 */
final readonly class VideoFileIngest
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private VideoProbePortInterface $probe,
        private LibraryMediaFilesInterface $mediaFiles,
        private TransactionPortInterface $transaction,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Probe outside the write transaction, then lock and recheck the indexed observations before linking.
     *
     * @param list<DiscoveredFile> $batch
     * @param Closure(DiscoveredFile, Video): void $link
     * @param Closure(): void $flush
     * @param (Closure(list<DiscoveredFile>): void)|null $prepareLink
     * @return VideoBatchImportResult Null paths mean a delete holds the library.
     */
    public function importBatch(
        Uuid $libraryId,
        array $batch,
        Closure $link,
        Closure $flush,
        ?Closure $prepareLink = null,
    ): array {
        $prepared = [];
        $refused = [];
        $failures = [];
        $hashes = $this->mediaFiles->findAvailableHashesByPaths(
            $libraryId,
            array_map(static fn(DiscoveredFile $file): string => $file->absolutePath, $batch),
        );
        foreach ($batch as $file) {
            if (($hashes[$file->absolutePath] ?? null) !== $file->hash) {
                continue;
            }
            try {
                $prepared[] = $this->prepare($file);
            } catch (UnsupportedMediaContainerException $error) {
                $refused[] = $file->absolutePath;
                $failures[] = $this->failure($file, $error);
            } catch (Throwable $error) {
                $failures[] = $this->failure($file, $error);
            }
        }

        if ($refused !== []) {
            $this->mediaFiles->markUnavailable($libraryId, $refused);
        }
        if ($prepared === []) {
            return ['writtenPaths' => [], 'failures' => $failures];
        }

        $writtenPaths = $this->transaction->run(function () use ($libraryId, $prepared, $link, $flush, $prepareLink, &$failures): ?array {
            if ($this->mediaFiles->isHeldByDeleteForImport($libraryId)) {
                return null;
            }
            $hashes = $this->mediaFiles->lockAvailableHashesByPathsForImport(
                $libraryId,
                array_map(static fn(array $entry): string => $entry['file']->absolutePath, $prepared),
            );
            $current = array_filter(
                $prepared,
                static fn(array $entry): bool => ($hashes[$entry['file']->absolutePath] ?? null) === $entry['file']->hash,
            );
            if ($current === []) {
                return [];
            }

            $prepareLink?->__invoke(
                array_map(static fn (array $entry): DiscoveredFile => $entry['file'], $current) |> array_values(...),
            );
            $written = [];
            foreach ($current as $entry) {
                try {
                    $video = $this->resolveVideo($entry);
                    if ($video === null) {
                        continue;
                    }
                    $link($entry['file'], $video);
                    $written[] = $entry['file']->absolutePath;
                } catch (Throwable $error) {
                    $failures[] = $this->failure($entry['file'], $error);
                }
            }
            $flush();

            return $written;
        });

        return ['writtenPaths' => $writtenPaths, 'failures' => $failures];
    }

    /** @return PreparedVideo */
    private function prepare(DiscoveredFile $file): array
    {
        $video = $this->videos->findByHash($file->hash);
        if ($video !== null) {
            $probe = $this->needsProbe($video, $file)
                ? $this->probe->probeVideo($file->absolutePath)->jsonSerialize()
                : null;

            return ['file' => $file, 'video' => $video, 'isNew' => false, 'probe' => $probe];
        }

        $probe = $this->probe->probeVideo($file->absolutePath);
        $video = Video::create(
            path: $file->absolutePath,
            hash: $file->hash,
            duration: (int) $probe->duration,
            height: $probe->height,
            width: $probe->width,
            videoBitrate: $probe->videoBitrate,
            framerate: (int) $probe->framerate,
        );
        $video->updateProbe($probe->jsonSerialize());

        return ['file' => $file, 'video' => $video, 'isNew' => true, 'probe' => null];
    }

    /** @param PreparedVideo $entry */
    private function resolveVideo(array $entry): ?Video
    {
        $video = $entry['video'];
        $file = $entry['file'];
        if ($entry['isNew']) {
            $existing = $this->videos->findByHash($file->hash);
            if ($existing !== null) {
                return $existing;
            }
            $this->videos->save($video);

            return $video;
        }

        $probe = $entry['probe'];
        if ($probe === null) {
            return $video;
        }
        $video = $this->videos->findFreshByUuid($video->getId());
        if ($video === null || $video->getHash() !== $file->hash) {
            return null;
        }
        $video->updateProbe($probe);
        $this->videos->save($video);

        return $video;
    }

    private function needsProbe(Video $video, DiscoveredFile $file): bool
    {
        $probe = $video->getProbe();
        if (!array_key_exists('chapters', $probe) || !isset($probe['containerFormat'], $probe['videoStreamIndex'])) {
            return true;
        }
        if (MediaContainer::identify(MediaKind::Video, (string) $probe['containerFormat'], $file->extension) === null) {
            return true;
        }
        foreach ($probe['audioStreams'] ?? [] as $stream) {
            if (!isset($stream['streamIndex'])) {
                return true;
            }
        }

        return false;
    }

    /** @return VideoImportFailure */
    private function failure(DiscoveredFile $file, Throwable $error): array
    {
        $this->logger->warning('Failed to process file', [
            'path' => $file->absolutePath,
            'error' => $error->getMessage(),
        ]);

        return ['path' => $file->absolutePath, 'error' => $error->getMessage()];
    }
}
