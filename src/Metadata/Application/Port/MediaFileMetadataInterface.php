<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

use App\Metadata\Application\Exception\UnreadableMediaFileException;

/**
 * Reads the tags embedded in a media file inside a library, and ranks candidate records against
 * them.
 *
 * The admin extract and match routes and their console commands share this contract, so both
 * entry points read the same tags and reject the same paths.
 */
interface MediaFileMetadataInterface
{
    /**
     * The file's detected format and its tags.
     *
     * @return array{format: string, metadata: array<string, mixed>}
     *
     * @throws UnreadableMediaFileException when no library holds a file at the path or its format is not supported
     */
    public function extract(string $path): array;

    /**
     * The candidates that fit the file's tags, best first; a candidate that fits too poorly is left out.
     *
     * @param array<array-key, array<string, mixed>> $candidates records to compare, each with at least a title
     *
     * @return list<array{source: mixed, sourceId: mixed, confidence: float, data: array<string, mixed>}>
     *
     * @throws UnreadableMediaFileException when no library holds a file at the path or its format is not supported
     */
    public function match(string $path, array $candidates): array;
}
