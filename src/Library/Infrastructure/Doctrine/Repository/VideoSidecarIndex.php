<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Application\DTO\MediaFile;
use App\Library\Application\Port\VideoSidecarIndexInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class VideoSidecarIndex implements VideoSidecarIndexInterface
{
    public function __construct(
        private Connection $connection,
        private LibraryRepositoryInterface $libraries,
    ) {}

    #[\Override]
    public function forFolder(Uuid $libraryId, string $folderPath): array
    {
        $root = $this->libraries->findByUuid($libraryId)?->getPath()->toString();
        if ($root === null) {
            return [];
        }
        $libraryPrefix = rtrim($root, '/') . '/';
        $prefix = $folderPath === '' ? $libraryPrefix : $libraryPrefix . $folderPath . '/';
        $sql = <<<'SQL'
            SELECT path FROM library_file_index
            WHERE library_id = :library AND available
              AND extension IN (:extensions) AND starts_with(path, :prefix)
            SQL;
        if ($folderPath === '') {
            $sql .= " AND strpos(substr(path, char_length(:prefix) + 1), '/') = 0";
        }
        $paths = $this->connection->fetchFirstColumn($sql . ' ORDER BY path', [
            'library' => $libraryId,
            'prefix' => $prefix,
            'extensions' => MediaFile::VIDEO_SIDECAR_EXTENSIONS,
        ], ['library' => 'uuid', 'extensions' => ArrayParameterType::STRING]);

        return array_map(static fn (string $path): array => [
            'path' => $path,
            'relativePath' => substr($path, strlen($libraryPrefix)),
        ], $paths);
    }
}
