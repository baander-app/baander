<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of DELETE /api/admin/recommendations/jobs/{publicId}. */
#[AsCommand(
    name: 'app:recommendation:job:cancel',
    description: 'Cancel a pending or running recommendation job.',
)]
final class RecommendationJobCancelCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publicId', InputArgument::REQUIRED, 'The job\'s public ID, as app:recommendation:job:list shows it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('publicId');

        try {
            $this->support->dispatch(new CancelRecommendationJobCommand($publicId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('Recommendation job "%s" is cancelled. A run stops at its next check.', $publicId));

        return Command::SUCCESS;
    }
}
