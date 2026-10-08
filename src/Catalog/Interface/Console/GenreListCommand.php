<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Interface\Resource\GenreResource;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/genres/.
 *
 * Lists every genre, as `?flat=true` does for an administrator, because the shell
 * reads with the unrestricted library scope.
 */
#[AsCommand(
    name: 'app:genre:list',
    description: 'List every genre, or show the genre hierarchy with --tree.',
)]
final class GenreListCommand extends Command
{
    public function __construct(
        private readonly GenrePortInterface $genres,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tree', null, InputOption::VALUE_NONE, 'Show the genres as a hierarchy, children indented below their parent');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $genres = GenreResource::collection($this->genres->findAllVisible(LibraryReadScope::unrestricted()));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if ($input->getOption('tree') === true && !AdminCommandSupport::wantsJson($input)) {
            if ($genres === []) {
                $io->text('No genres exist.');
            }
            foreach (self::tree($genres) as $line) {
                $io->writeln($line, OutputInterface::OUTPUT_PLAIN);
            }

            return Command::SUCCESS;
        }

        $slugs = array_column($genres, 'slug', 'uuid');

        return AdminCommandSupport::list(
            $input,
            $io,
            $genres,
            ['Name', 'Slug', 'Parent', 'UUID', 'MusicBrainz ID'],
            static fn (array $genre): array => [
                $genre['name'],
                $genre['slug'],
                $genre['parentId'] === null ? '-' : ($slugs[$genre['parentId']] ?? $genre['parentId']),
                $genre['uuid'],
                $genre['mbid'] ?? '-',
            ],
            'No genres exist.',
        );
    }

    /**
     * One line per genre, children indented two spaces below their parent, siblings by name.
     *
     * @param list<array<string, mixed>> $genres sorted by name
     * @return list<string>
     */
    private static function tree(array $genres): array
    {
        $byId = array_column($genres, null, 'uuid');
        $children = [];
        $roots = [];
        foreach ($genres as $genre) {
            $parentId = $genre['parentId'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $children[$parentId][] = $genre;
            } else {
                $roots[] = $genre;
            }
        }

        $lines = [];
        $printed = [];
        $walk = static function (array $genre, int $depth) use (&$walk, &$lines, &$printed, $children): void {
            // A genre is printed once, so a parent cycle in stored data cannot loop.
            if (isset($printed[$genre['uuid']])) {
                return;
            }
            $printed[$genre['uuid']] = true;
            $lines[] = sprintf('%s%s (%s)', str_repeat('  ', $depth), $genre['name'], $genre['slug']);
            foreach ($children[$genre['uuid']] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0);
        }
        // Genres in a stored cycle have no root above them; list them at the top level.
        foreach ($genres as $genre) {
            $walk($genre, 0);
        }

        return $lines;
    }
}
