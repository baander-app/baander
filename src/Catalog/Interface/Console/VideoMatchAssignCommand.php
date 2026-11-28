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

#[AsCommand(name: 'app:video:match:assign', description: 'Assign a file to episodes or an extra kind.')]
final class VideoMatchAssignCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'Video placement public ID')
            ->addOption('episodes', null, InputOption::VALUE_REQUIRED, 'Comma-separated episode public IDs')
            ->addOption('extra-kind', null, InputOption::VALUE_REQUIRED, 'Assign the file as this kind of extra');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $declined = AdminCommandSupport::confirm($input, $io, 'Replace this file assignment?');
        if ($declined !== null) {
            return $declined;
        }
        try {
            $episodeOption = AdminCommandSupport::stringOption($input, 'episodes');
            $episodes = $episodeOption === null ? [] : explode(',', $episodeOption);
            $episodes = array_map(trim(...), $episodes);
            $data = $this->matches->assignFile(
                (string) $input->getArgument('public-id'),
                $episodes,
                AdminCommandSupport::stringOption($input, 'extra-kind'),
            );
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->success('The file assignment was applied.');
        $io->writeln(AdminCommandSupport::prettyJson($data), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
