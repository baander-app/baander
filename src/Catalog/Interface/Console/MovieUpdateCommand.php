<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Movie\UpdateMovieCommand;
use App\Catalog\Interface\Resource\MovieResource;
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
 * The CLI counterpart of PATCH /api/movies/{publicId}.
 */
#[AsCommand(
    name: 'app:movie:update',
    description: "Change a movie's title, year or summary.",
)]
final class MovieUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-id', InputArgument::REQUIRED, 'The public ID of the movie')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The new title')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'The new release year')
            ->addOption('summary', null, InputOption::VALUE_REQUIRED, 'The new summary');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $movie = MovieResource::from($this->support->dispatch(new UpdateMovieCommand(
                publicId: (string) $input->getArgument('public-id'),
                title: AdminCommandSupport::stringOption($input, 'title'),
                year: AdminCommandSupport::integerOption($input, 'year'),
                summary: AdminCommandSupport::stringOption($input, 'summary'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $movie);
        }

        $io->success(sprintf('Movie "%s" (%s) updated.', $movie['title'], $movie['publicId']));

        return Command::SUCCESS;
    }
}
