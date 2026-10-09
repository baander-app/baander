<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/genres/{slug}/albums.
 */
#[AsCommand(
    name: 'app:genre:album:add',
    description: 'Assign a genre to an album.',
)]
final class GenreAlbumAddCommand extends Command
{
    public function __construct(
        private readonly GenrePortInterface $genres,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'The genre slug')
            ->addArgument('album-id', InputArgument::REQUIRED, 'The album UUID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('slug');

        try {
            $link = GenreLinkTarget::resolve($this->genres, $slug, (string) $input->getArgument('album-id'), 'album');
            if (!$this->genres->addAlbumToGenre($link->genreId, $link->targetId)) {
                throw new NotFoundException('Album not found.');
            }
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Album %s has the genre "%s".', $link->targetId->toString(), $slug));
        }

        return Command::SUCCESS;
    }
}
