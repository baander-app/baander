<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Messaging;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Application\Messaging\PayloadSchema;
use App\Shared\Domain\Model\Uuid;

final readonly class LibraryMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'library.scan' => ['library_slug', 'rescan', 'claim_id'],
        'library.files_discovered' => ['library_id', 'library_type', 'directory', 'files'],
    ];

    public function types(): array
    {
        return [
            'library.scan' => ScanLibraryCommand::class,
            'library.files_discovered' => FilesDiscovered::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof ScanLibraryCommand => [$message->getLibrarySlug()->toString(), $message->isRescan(), $message->getClaimId()?->toString()],
            $message instanceof FilesDiscovered => [$message->libraryId->toString(), $message->libraryType, $message->directory, array_map(
                static fn (DiscoveredFile $file): array => [
                    'absolute_path' => $file->absolutePath, 'relative_path' => $file->relativePath, 'extension' => $file->extension,
                    'size' => $file->size, 'modified_at' => $file->modifiedAt, 'hash' => $file->hash,
                ], $message->files,
            )],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'library.scan' => new ScanLibraryCommand(
                new LibrarySlug($p['library_slug']),
                $p['rescan'],
                $p['claim_id'] === null ? null : Uuid::fromString($p['claim_id']),
            ),
            'library.files_discovered' => new FilesDiscovered(Uuid::fromString($p['library_id']), $p['library_type'], $p['directory'], $this->decodeFiles($p['files'])),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    /** @return list<DiscoveredFile> */
    private function decodeFiles(mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new \InvalidArgumentException('Files must be a list.');
        }
        return array_map(function (array $file): DiscoveredFile {
            PayloadSchema::requireFields($file, ['absolute_path', 'relative_path', 'extension', 'size', 'modified_at', 'hash']);
            return new DiscoveredFile($file['absolute_path'], $file['relative_path'], $file['extension'], $file['size'], $file['modified_at'], $file['hash']);
        }, $files);
    }
}
