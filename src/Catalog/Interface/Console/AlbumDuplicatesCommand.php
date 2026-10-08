<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/albums/duplicates.
 */
#[AsCommand(
    name: 'app:album:duplicates',
    description: 'List the groups of albums in a library that look like duplicates.',
)]
final class AlbumDuplicatesCommand extends Command
{
    public function __construct(
        private readonly AlbumDuplicatePortInterface $duplicates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'The UUID of the library to search');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $library = AdminCommandSupport::uuid($input->getArgument('library'), 'The library ID');
            $groups = DuplicateGroupResource::collection($this->duplicates->findDuplicates($library));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $groups);
        }

        if ($groups === []) {
            $io->text('No duplicate albums found in the library.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Group', 'Confidence', 'Public ID', 'Title', 'Year', 'Label', 'Artists'],
            self::rows($groups),
        );

        return Command::SUCCESS;
    }

    /**
     * One row per album; the group number and confidence head each group.
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return list<list<mixed>|TableSeparator>
     */
    private static function rows(array $groups): array
    {
        $rows = [];

        foreach ($groups as $index => $group) {
            if ($index > 0) {
                $rows[] = new TableSeparator();
            }

            /** @var list<array<string, mixed>> $albums */
            $albums = $group['albums'];
            foreach ($albums as $position => $album) {
                /** @var list<array{name: string}> $artists */
                $artists = $album['artists'] ?? [];
                $rows[] = [
                    $position === 0 ? $index + 1 : '',
                    $position === 0 ? sprintf('%d%%', (int) round((float) $group['confidence'] * 100)) : '',
                    $album['publicId'],
                    $album['title'],
                    $album['year'] ?? '-',
                    $album['label'] ?? '-',
                    $artists === [] ? '-' : implode(', ', array_column($artists, 'name')),
                ];
            }
        }

        return $rows;
    }
}
