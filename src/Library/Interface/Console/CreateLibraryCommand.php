<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\CreateLibraryCommand as CreateLibraryCommandMessage;
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

/** The CLI counterpart of POST /api/libraries. */
#[AsCommand(
    name: 'app:library:create',
    description: 'Create a new media library.',
)]
final class CreateLibraryCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Library name')
            ->addArgument('path', InputArgument::REQUIRED, 'Absolute path to the media directory')
            ->addArgument('type', InputArgument::REQUIRED, 'Library type: music, podcast, audiobook, movie or tv_show')
            ->addOption('filesystem-type', null, InputOption::VALUE_REQUIRED, 'Filesystem backend', 'local')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'URL-friendly slug (generated from the name if omitted)')
            ->addOption('sort-order', null, InputOption::VALUE_REQUIRED, 'Sort order', '0');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getOption('slug');

        try {
            $library = LibraryResource::from($this->support->dispatch(new CreateLibraryCommandMessage(
                name: (string) $input->getArgument('name'),
                path: (string) $input->getArgument('path'),
                type: (string) $input->getArgument('type'),
                filesystemType: (string) $input->getOption('filesystem-type'),
                slug: is_string($slug) ? $slug : null,
                sortOrder: AdminCommandSupport::integerOption($input, 'sort-order') ?? 0,
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $library);
        }

        $io->success('Library created successfully.');
        LibraryTable::details($io, $library);
        $io->note('No user was granted access to the library, so nobody receives its scan-completed notifications. Admins still see and manage it.');

        return Command::SUCCESS;
    }
}
