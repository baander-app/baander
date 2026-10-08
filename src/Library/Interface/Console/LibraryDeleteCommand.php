<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\DeleteLibraryCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of DELETE /api/libraries/{id}. */
#[AsCommand(
    name: 'app:library:delete',
    description: 'Delete a media library.',
)]
final class LibraryDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'Library UUID or slug');
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $library = (string) $input->getArgument('library');

        $notConfirmed = AdminCommandSupport::confirm($input, $io, sprintf('Delete the library "%s"? This cannot be undone.', $library));
        if ($notConfirmed !== null) {
            return $notConfirmed;
        }

        try {
            $this->support->dispatch(new DeleteLibraryCommand($library));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('Library "%s" has been deleted.', $library));

        return Command::SUCCESS;
    }
}
