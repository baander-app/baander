<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:video:match:apply', description: 'Apply an explicit provider match to a series or movie.')]
final class VideoMatchApplyCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, 'series or movie')
            ->addArgument('public-id', InputArgument::REQUIRED, 'Title public ID')
            ->addArgument('provider', InputArgument::REQUIRED, 'tvdb, tmdb or local')
            ->addArgument('candidate-id', InputArgument::REQUIRED, 'Provider candidate ID');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $declined = AdminCommandSupport::confirm($input, $io, 'Apply this provider match to the title?');
        if ($declined !== null) {
            return $declined;
        }
        try {
            $data = $this->matches->rematchTitle(
                (string) $input->getArgument('kind'),
                (string) $input->getArgument('public-id'),
                (string) $input->getArgument('provider'),
                (string) $input->getArgument('candidate-id'),
            );
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->success('The provider match was applied.');
        $io->writeln(AdminCommandSupport::prettyJson($data), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
