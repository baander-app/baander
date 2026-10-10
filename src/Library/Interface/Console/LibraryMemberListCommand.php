<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Query\ListLibraryAccessQuery;
use App\Library\Interface\Resource\LibraryAccessResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/users/{userId}/libraries. */
#[AsCommand(
    name: 'app:library:member:list',
    description: 'List every library with whether a user may see it.',
)]
final class LibraryMemberListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::REQUIRED, 'The user, by email address or UUID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $entries = LibraryAccessResource::collection($this->support->dispatch(new ListLibraryAccessQuery((string) $input->getArgument('user'))));
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            $entries,
            ['Library', 'Slug', 'Type', 'Access'],
            static fn (array $entry): array => [$entry['name'], $entry['slug'], $entry['type'], AdminCommandSupport::yesNo($entry['granted'] === true)],
            'No library exists.',
        );
    }
}
