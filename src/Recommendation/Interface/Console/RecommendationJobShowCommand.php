<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Query\GetRecommendationJobQuery;
use App\Recommendation\Interface\Resource\RecommendationJobResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/recommendations/jobs/{publicId}. */
#[AsCommand(
    name: 'app:recommendation:job:show',
    description: 'Show one recommendation job: status, progress, counts and metadata.',
)]
final class RecommendationJobShowCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publicId', InputArgument::REQUIRED, 'The job\'s public ID, as app:recommendation:job:list shows it');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $job = RecommendationJobResource::from($this->support->dispatch(new GetRecommendationJobQuery((string) $input->getArgument('publicId'))));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $job);
        }

        $io->definitionList(
            ['Public ID' => $job['public_id']],
            ['Status' => $job['status']],
            ['Mode' => $job['is_full'] ? 'full' : 'incremental'],
            ['Progress' => sprintf('%s%% (%d of %d songs)', $job['progress_percentage'], $job['completed_songs'], $job['total_songs'])],
            ['Current strategy' => $job['current_strategy'] !== '' ? $job['current_strategy'] : '-'],
            ['Created' => $job['created_at']],
            ['Started' => $job['started_at'] ?? '-'],
            ['Completed' => $job['completed_at'] ?? '-'],
            ['Fail reason' => $job['fail_reason'] ?? '-'],
            ['Requeue of' => $job['original_job_id'] ?? '-'],
        );

        if ($job['strategy_counts'] !== []) {
            $io->table(
                ['Strategy', 'Recommendations saved'],
                array_map(static fn (int|string $strategy, mixed $count): array => [(string) $strategy, $count], array_keys($job['strategy_counts']), $job['strategy_counts']),
            );
        }

        if ($job['metadata'] !== []) {
            $io->text('Metadata:');
            $io->writeln(json_encode($job['metadata'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Command::SUCCESS;
    }
}
