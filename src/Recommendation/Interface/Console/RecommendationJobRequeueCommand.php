<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Command\RequeueRecommendationJobCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Shared\Application\Actor;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/recommendations/jobs/{publicId}/requeue.
 *
 * The new job runs in this process, like app:recommendation:generate, and the run is
 * recorded in the job monitor.
 */
#[AsCommand(
    name: 'app:recommendation:job:requeue',
    description: 'Run a failed or cancelled recommendation job again as a new job, in this process.',
)]
final class RecommendationJobRequeueCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publicId', InputArgument::REQUIRED, 'The failed or cancelled job\'s public ID, as app:recommendation:job:list shows it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('publicId');

        $io->text(sprintf('Requeueing recommendation job "%s" and running the new job in this process.', $publicId));

        try {
            $run = $this->jobMonitor->runInline(new RequeueRecommendationJobCommand($publicId, Actor::CLI));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $result = $run->result;
        assert($result instanceof RecommendationGenerationResult);

        return RecommendationGenerateCommand::report($io, $result, $run->jobId);
    }
}
