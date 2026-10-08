<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Genre\UpdateGenreCommand;
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
 * The CLI counterpart of PATCH /api/genres/{slug}.
 */
#[AsCommand(
    name: 'app:genre:update',
    description: 'Rename a genre, change its slug or MusicBrainz ID, or move it below another parent.',
)]
final class GenreUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'The current slug of the genre')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new name')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'The new slug')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'UUID of the new parent genre')
            ->addOption('mbid', null, InputOption::VALUE_REQUIRED, 'The new MusicBrainz ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $genre = GenreResource::from($this->support->dispatch(new UpdateGenreCommand(
                slug: (string) $input->getArgument('slug'),
                name: AdminCommandSupport::stringOption($input, 'name'),
                newSlug: AdminCommandSupport::stringOption($input, 'slug'),
                parentId: AdminCommandSupport::stringOption($input, 'parent'),
                mbid: AdminCommandSupport::stringOption($input, 'mbid'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('Genre "%s" (%s) updated.', $genre['name'], $genre['slug']));

        return Command::SUCCESS;
    }
}
