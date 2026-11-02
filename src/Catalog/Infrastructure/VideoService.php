<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use InvalidArgumentException;

final readonly class VideoService implements VideoPortInterface
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private LibraryMediaFileResolverInterface $mediaFiles,
    ) {}

    public function getSourceMetadata(PublicId $publicId, LibraryReadScope $scope): ?VideoSourceMetadata
    {
        $libraries = $this->videos->getLibraryIdsByPublicId($publicId, $scope);
        if ($libraries === []) {
            return null;
        }
        $video = $this->videos->findByPublicId($publicId);
        if ($video === null) {
            return null;
        }

        foreach ($libraries as $library) {
            try {
                $path = $this->mediaFiles->resolve($library, $video->getPath());
            } catch (InvalidArgumentException) {
                continue;
            }
            $probe = $video->getProbe();
            $size = filesize($path);

            return new VideoSourceMetadata(
                videoId: $video->getId(),
                publicId: $video->getPublicId()->toString(),
                filePath: $path,
                filename: basename($path),
                size: $size === false ? 0 : $size,
                mimeType: (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream',
                hash: $video->getHash(),
                containerFormat: $probe['containerFormat'] ?? null,
                videoCodec: $probe['videoCodec'] ?? null,
                videoStreamIndex: $probe['videoStreamIndex'] ?? null,
                audioStreams: $probe['audioStreams'] ?? [],
                width: $probe['width'] ?? $video->getWidth(),
                height: $probe['height'] ?? $video->getHeight(),
                framerate: isset($probe['framerate']) ? (float) $probe['framerate'] : null,
                duration: isset($probe['duration']) ? (float) $probe['duration'] : null,
                containerStartTime: $probe['containerStartTime'] ?? null,
                videoStartTime: $probe['videoStartTime'] ?? null,
                videoTimeBase: $probe['videoTimeBase'] ?? null,
                pixFmt: $probe['pixFmt'] ?? null,
                colorSpace: $probe['colorSpace'] ?? null,
                colorRange: $probe['colorRange'] ?? null,
                sourceTimestampOrigin: $probe['sourceTimestampOrigin'] ?? null,
            );
        }

        return null;
    }

    public function findByPublicId(PublicId $publicId): ?Video
    {
        return $this->videos->findByPublicId($publicId);
    }

    public function isVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): bool
    {
        return $this->videos->isVisibleByPublicId($publicId, $scope);
    }

    public function findByUuid(Uuid $id): ?Video
    {
        return $this->videos->findByUuid($id);
    }
}
