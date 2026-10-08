<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

/** How the `app:library:*` commands print a library, from the API's LibraryResource. */
final class LibraryTable
{
    public const array LIST_HEADERS = ['Name', 'Slug', 'Type', 'Path', 'Scan status', 'Last scan', 'UUID'];

    /**
     * @param array<string, mixed> $library a LibraryResource
     *
     * @return list<mixed>
     */
    public static function row(array $library): array
    {
        return [
            $library['name'],
            $library['slug'],
            $library['type'],
            $library['path'],
            $library['scanStatus'] ?? '-',
            $library['lastScan'] ?? 'never',
            $library['id'],
        ];
    }

    /** @param array<string, mixed> $library a LibraryResource */
    public static function details(SymfonyStyle $io, array $library): void
    {
        $io->horizontalTable(
            ['UUID', 'Name', 'Slug', 'Path', 'Type', 'Filesystem', 'Sort order', 'Scan status', 'Last scan', 'Created', 'Updated'],
            [[
                $library['id'],
                $library['name'],
                $library['slug'],
                $library['path'],
                $library['type'],
                $library['filesystemType'],
                (string) $library['sortOrder'],
                $library['scanStatus'] ?? '-',
                $library['lastScan'] ?? 'never',
                $library['createdAt'],
                $library['updatedAt'],
            ]],
        );
    }
}
