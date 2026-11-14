<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Application\Port\MediaFileMetadataInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/metadata/match.
 *
 * Reads a media file's tags and lists the candidate records that fit them, best first.
 */
#[AsCommand(
    name: 'app:metadata:file:match',
    description: 'Rank candidate records by how well they fit the tags of a media file on the server.',
)]
final class MetadataFileMatchCommand extends Command
{
    public function __construct(
        private readonly MediaFileMetadataInterface $files,
        private readonly MetadataFileInputReader $inputReader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        MetadataFileInputReader::addPathArgument($this);
        MetadataFileInputReader::addCandidatesOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $request = $this->inputReader->matchRequest($input);
            $matches = $this->files->match($request->path, $request->candidates);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            $matches,
            ['Confidence', 'Source', 'Source ID', 'Title', 'Artist', 'Album', 'Year', 'MBID'],
            static fn (array $match): array => [
                sprintf('%.2f', $match['confidence']),
                ...array_map(self::cell(...), [
                    $match['source'],
                    $match['sourceId'],
                    $match['data']['title'],
                    $match['data']['artist'],
                    $match['data']['album'],
                    $match['data']['year'],
                    $match['data']['mbid'],
                ]),
            ],
            'No candidate fits the tags of the file.',
        );
    }

    private static function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
