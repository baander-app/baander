<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Query\ListRecommendationJobsQuery;
use App\Recommendation\Interface\Resource\RecommendationJobResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/recommendations/jobs. */
#[AsCommand(
    name: 'app:recommendation:job:list',
    description: 'List the most recent recommendation jobs, newest first.',
)]
final class RecommendationJobListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only jobs with this status: pending, in_progress, completed, failed or cancelled')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Jobs to list, 1-%d', ListRecommendationJobsQuery::MAX_LIMIT), (string) ListRecommendationJobsQuery::DEFAULT_LIMIT);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $limit = AdminCommandSupport::integerOption($input, 'limit') ?? ListRecommendationJobsQuery::DEFAULT_LIMIT;
            $status = $input->getOption('status');
            $jobs = $this->support->dispatch(new ListRecommendationJobsQuery($limit, is_string($status) ? $status : null));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert(is_array($jobs));

        return AdminCommandSupport::list(
            $input,
            $io,
            RecommendationJobResource::collection($jobs),
            ['Public ID', 'Status', 'Mode', 'Strategy', 'Created', 'Completed', 'Fail reason'],
            static fn (array $job): array => [
                $job['public_id'],
                $job['status'],
                $job['is_full'] ? 'full' : 'incremental',
                $job['current_strategy'] !== '' ? $job['current_strategy'] : '-',
                $job['created_at'],
                $job['completed_at'] ?? '-',
                $job['fail_reason'] ?? '-',
            ],
            'No recommendation jobs are recorded.',
        );
    }
}
