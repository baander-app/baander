<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Application\DTO\MediaFile;
use App\Library\Application\Port\VideoSidecarIndexInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class VideoSidecarIndex implements VideoSidecarIndexInterface
{
    private const array NFO_SUFFIXES = ['.nfo', '.nfO', '.nFo', '.nFO', '.Nfo', '.NfO', '.NFo', '.NFO'];

    public function __construct(
        private Connection $connection,
        private LibraryRepositoryInterface $libraries,
    ) {}

    #[\Override]
    public function forFolder(Uuid $libraryId, string $folderPath): array
    {
        $libraryPrefix = $this->libraryPrefix($libraryId);
        if ($libraryPrefix === null) {
            return [];
        }
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

        return self::sidecars($paths, $libraryPrefix);
    }

    #[\Override]
    public function forRootNfoStems(Uuid $libraryId, array $stems): array
    {
        $stems = array_unique(array_filter($stems, static fn (string $stem): bool => !str_contains($stem, '/')));
        if ($stems === [] || ($prefix = $this->libraryPrefix($libraryId)) === null) {
            return [];
        }
        $paths = [];
        foreach ($stems as $stem) {
            foreach (self::NFO_SUFFIXES as $suffix) {
                $paths[] = $prefix . $stem . $suffix;
            }
        }
        $nfos = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT path FROM library_file_index
                WHERE library_id = :library AND available AND extension = 'nfo'
                  AND path = ANY (ARRAY(SELECT jsonb_array_elements_text(CAST(:paths AS jsonb))))
                ORDER BY path
                SQL,
            ['library' => $libraryId, 'paths' => $paths],
            ['library' => 'uuid', 'paths' => Types::JSON],
        );

        return self::sidecars($nfos, $prefix);
    }

    private function libraryPrefix(Uuid $libraryId): ?string
    {
        $root = $this->libraries->findByUuid($libraryId)?->getPath()->toString();

        return $root === null ? null : rtrim($root, '/') . '/';
    }

    /**
     * @param list<string> $paths
     * @return list<array{path: string, relativePath: string}>
     */
    private static function sidecars(array $paths, string $libraryPrefix): array
    {
        return array_map(static fn (string $path): array => [
            'path' => $path,
            'relativePath' => substr($path, strlen($libraryPrefix)),
        ], $paths);
    }
}
