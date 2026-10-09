<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Genre\CreateGenreCommand;
use App\Catalog\Interface\Resource\GenreResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/genres/.
 */
#[AsCommand(
    name: 'app:genre:create',
    description: 'Create a genre, optionally below a parent genre.',
)]
final class GenreCreateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The genre name')
            ->addArgument('slug', InputArgument::REQUIRED, 'The URL slug: lowercase letters, digits and single hyphens')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'UUID of the parent genre')
            ->addOption('mbid', null, InputOption::VALUE_REQUIRED, 'MusicBrainz ID of the genre');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parent = $input->getOption('parent');
        $mbid = $input->getOption('mbid');

        try {
            $genre = GenreResource::from($this->support->dispatch(new CreateGenreCommand(
                name: (string) $input->getArgument('name'),
                slug: (string) $input->getArgument('slug'),
                parentId: is_string($parent) ? $parent : null,
                mbid: is_string($mbid) ? $mbid : null,
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $genre);
        }

        $io->success(sprintf('Genre "%s" (%s) created with UUID %s.', $genre['name'], $genre['slug'], $genre['uuid']));

        return Command::SUCCESS;
    }
}
