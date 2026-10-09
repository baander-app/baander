<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/movies/{publicId}.
 */
#[AsCommand(
    name: 'app:movie:delete',
    description: 'Delete a movie and the videos no other movie uses.',
)]
final class MovieDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'The movie public ID');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');

        $refused = AdminCommandSupport::confirm($input, $io, sprintf('Delete movie %s and its videos? This cannot be undone.', $publicId));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $result = $this->support->dispatch(new DeleteMovieCommand($publicId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return CatalogDeleteOutput::result($input, $io, $result, sprintf('Movie %s', $publicId));
    }
}
