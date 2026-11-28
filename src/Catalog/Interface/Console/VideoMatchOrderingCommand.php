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

#[AsCommand(name: 'app:video:match:order', description: 'Choose an ordering offered by the matched series provider.')]
final class VideoMatchOrderingCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'Series public ID')
            ->addArgument('ordering', InputArgument::REQUIRED, 'Exact provider ordering ID');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $declined = AdminCommandSupport::confirm($input, $io, 'Change this series ordering and place its files again?');
        if ($declined !== null) {
            return $declined;
        }
        try {
            $data = $this->matches->setOrdering((string) $input->getArgument('public-id'), (string) $input->getArgument('ordering'));
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->success('The series ordering was applied.');
        $io->writeln(AdminCommandSupport::prettyJson($data), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
