<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use App\Catalog\Interface\Resource\VideoLibraryRematchResource;

#[AsCommand(name: 'app:video:match:rematch', description: 'Preview or confirm a library rematch, optionally replacing manual matches.')]
final class VideoMatchRematchCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'Library public ID or slug')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Confirm and queue the library rematch')
            ->addOption('include-hand-matched', null, InputOption::VALUE_NONE, 'Include manually matched titles when applying');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $library = (string) $input->getArgument('library');
            $result = $this->matches->previewLibraryRematch($library);
            if ($input->getOption('apply') === true) {
                $io->getErrorStyle()->text(sprintf(
                    '%d titles, including %d manually matched titles and %d manually assigned files. Replaced matches reset their ordering and return manually assigned files to review.',
                    $result['totalTitles'],
                    $result['handMatchedTitles'],
                    $result['handAssignedFiles'],
                ));
                $declined = AdminCommandSupport::confirm($input, $io, 'Queue this library rematch?');
                if ($declined !== null) {
                    return $declined;
                }
                $result = $this->matches->rematchLibrary($library, confirmed: true, force: $input->getOption('include-hand-matched') === true);
            }
            $data = VideoLibraryRematchResource::from($result);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->writeln(AdminCommandSupport::prettyJson($data), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
