<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\GrantLibraryAccessCommand;
use App\Library\Interface\Resource\LibraryAccessResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of PUT /api/admin/users/{userId}/libraries/{libraryId}. */
#[AsCommand(
    name: 'app:library:member:grant',
    description: 'Let a user see a library.',
)]
final class LibraryMemberGrantCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'The user, by email address or UUID')
            ->addArgument('library', InputArgument::REQUIRED, 'Library UUID or slug');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = (string) $input->getArgument('user');

        try {
            $entry = LibraryAccessResource::from($this->support->dispatch(new GrantLibraryAccessCommand($user, (string) $input->getArgument('library'))));
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $entry);
        }

        $io->success(sprintf('%s can see library "%s".', $user, $entry['slug']));

        return Command::SUCCESS;
    }
}
