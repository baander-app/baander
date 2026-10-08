<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Query\ListLibrariesQuery;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/libraries. */
#[AsCommand(
    name: 'app:library:list',
    description: 'List every media library.',
)]
final class LibraryListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only libraries of this type: music, podcast, audiobook, movie or tv_show');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');

        try {
            $libraries = $this->support->dispatch(new ListLibrariesQuery(
                LibraryReadScope::unrestricted(),
                is_string($type) ? $type : null,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert(is_array($libraries));

        return AdminCommandSupport::list(
            $input,
            $io,
            LibraryResource::collection($libraries),
            LibraryTable::LIST_HEADERS,
            LibraryTable::row(...),
            'No libraries exist.',
        );
    }
}
