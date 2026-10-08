<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\UpdateLibraryCommand;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of PATCH /api/libraries/{id}. */
#[AsCommand(
    name: 'app:library:update',
    description: 'Rename a media library or change its sort order.',
)]
final class LibraryUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('library', InputArgument::REQUIRED, 'Library UUID or slug')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new name')
            ->addOption('sort-order', null, InputOption::VALUE_REQUIRED, 'The new sort order; lower numbers come first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('name');

        try {
            $library = LibraryResource::from($this->support->dispatch(new UpdateLibraryCommand(
                library: (string) $input->getArgument('library'),
                name: is_string($name) ? $name : null,
                sortOrder: AdminCommandSupport::integerOption($input, 'sort-order'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('Library "%s" has been updated.', $library['slug']));
        LibraryTable::details($io, $library);

        return Command::SUCCESS;
    }
}
