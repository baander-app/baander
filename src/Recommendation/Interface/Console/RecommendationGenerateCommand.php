<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Shared\Application\Actor;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/recommendations/generate.
 *
 * The job runs in this process, because the CPU process pool the web server uses lives
 * inside the web server. It leaves the same recommendation job record as the web path,
 * and the run is recorded in the job monitor.
 */
#[AsCommand(
    name: 'app:recommendation:generate',
    description: 'Generate music recommendations with every strategy, as a recommendation job run in this process.',
)]
final class RecommendationGenerateCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_REQUIRED,
                '"full" replaces the recommendations of every song; "incremental" only of songs updated in the last 7 days',
                GenerateRecommendationsCommand::MODE_FULL,
            )
            ->addOption(
                'user-id',
                'u',
                InputOption::VALUE_REQUIRED,
                'Store the collaborative recommendations for this user (UUID) instead of for everyone',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = (string) $input->getOption('mode');

        try {
            $userId = self::userId($input->getOption('user-id'));
        } catch (InvalidInputException $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->text(sprintf(
            'Generating %s recommendations for %s in this process. Follow the job from another shell with app:recommendation:job:list.',
            $mode,
            $userId === null ? 'all users' : 'user ' . $userId->toString(),
        ));

        try {
            $run = $this->jobMonitor->runInline(new GenerateRecommendationsCommand(
                mode: $mode,
                userId: $userId,
                actor: Actor::CLI,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $result = $run->result;
        assert($result instanceof RecommendationGenerationResult);

        return self::report($io, $result, $run->jobId);
    }

    /** Prints the job, its monitor record and the recommendations each strategy saved. */
    public static function report(SymfonyStyle $io, RecommendationGenerationResult $result, string $monitorJobId): int
    {
        $io->definitionList(
            ['Recommendation job' => $result->publicId],
            ['Job monitor ID' => $monitorJobId],
            ['Mode' => $result->mode],
            ['Status' => $result->status],
        );

        if ($result->counts !== []) {
            $io->table(
                ['Strategy', 'Recommendations saved'],
                array_map(static fn (string $strategy, int $count): array => [$strategy, $count], array_keys($result->counts), $result->counts),
            );
        }

        if ($result->status !== 'completed') {
            $io->getErrorStyle()->error(sprintf(
                'The job was %s before it finished; the strategies listed above ran.',
                $result->status,
            ));

            return Command::FAILURE;
        }

        $io->success('Recommendation generation completed.');

        return Command::SUCCESS;
    }

    private static function userId(mixed $value): ?Uuid
    {
        if ($value === null) {
            return null;
        }

        return AdminCommandSupport::uuid($value, '--user-id');
    }
}
