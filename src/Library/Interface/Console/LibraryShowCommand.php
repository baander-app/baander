<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Query\GetLibraryQuery;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/libraries/{id}. */
#[AsCommand(
    name: 'app:library:show',
    description: 'Show one media library.',
)]
final class LibraryShowCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'Library UUID or slug');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $library = LibraryResource::from($this->support->dispatch(new GetLibraryQuery(
                (string) $input->getArgument('library'),
                LibraryReadScope::unrestricted(),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $library);
        }

        LibraryTable::details($io, $library);

        return Command::SUCCESS;
    }
}
