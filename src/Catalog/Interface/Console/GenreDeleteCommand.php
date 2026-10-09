<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Genre\DeleteGenreCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/genres/{slug}.
 */
#[AsCommand(
    name: 'app:genre:delete',
    description: 'Delete a genre; its child genres become root genres.',
)]
final class GenreDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'The slug of the genre to delete');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('slug');

        $refused = AdminCommandSupport::confirm($input, $io, sprintf(
            'Delete the genre "%s"? Its child genres become root genres and its album and song links are removed.',
            $slug,
        ));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->support->dispatch(new DeleteGenreCommand($slug));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Genre "%s" deleted.', $slug));
        }

        return Command::SUCCESS;
    }
}
