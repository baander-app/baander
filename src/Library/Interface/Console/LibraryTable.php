<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Console\Input\InputInterface;
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

    /** The value of an integer option, or null when it is absent. */
    public static function integerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidInputException(sprintf('--%s must be an integer.', $name));
        }

        return $integer;
    }
}
