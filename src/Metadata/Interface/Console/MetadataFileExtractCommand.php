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
 * The CLI counterpart of POST /api/metadata/extract.
 *
 * Reads the tags embedded in a media file with the readers the library scan uses.
 */
#[AsCommand(
    name: 'app:metadata:file:extract',
    description: 'Read the tags embedded in a media file on the server.',
)]
final class MetadataFileExtractCommand extends Command
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
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $data = $this->inputReader->extractRequest($input)->path |> $this->files->extract(...);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->text(sprintf('Format: %s', $data['format']));
        $tags = array_keys($data['metadata']);
        $io->table(
            ['Tag', 'Value'],
            array_map(
                static fn (string $tag, mixed $value): array => [$tag, self::value($value)],
                $tags,
                $data['metadata'],
            ),
        );

        return Command::SUCCESS;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null, $value === [] => '-',
            is_array($value) => implode(', ', $value),
            is_scalar($value) => (string) $value,
            default => AdminCommandSupport::prettyJson($value),
        };
    }
}
