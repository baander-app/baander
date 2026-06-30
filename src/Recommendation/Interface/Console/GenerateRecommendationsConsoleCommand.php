<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:recommendations:generate',
    description: 'Generate music recommendations using all available strategies.',
)]
final class GenerateRecommendationsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Generation mode: "full" (clear and regenerate) or "incremental" (only new/modified content)',
                'full',
            )
            ->addOption(
                'user-id',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Generate recommendations for a specific user (UUID). If not specified, generates for all users.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mode = $input->getOption('mode');
        if (!in_array($mode, ['full', 'incremental'], true)) {
            $io->error('Invalid mode. Use "full" or "incremental".');

            return Command::FAILURE;
        }

        $userId = $input->getOption('user-id');
        if ($userId !== null) {
            try {
                $userId = new Uuid($userId);
            } catch (\InvalidArgumentException $e) {
                $io->error(sprintf('Invalid user ID format: %s', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $io->title('Generating Recommendations');
        $io->text(sprintf('Mode: <info>%s</info>', $mode));
        if ($userId !== null) {
            $io->text(sprintf('User: <info>%s</info>', $userId->toString()));
        } else {
            $io->text('User: <info>all users</info>');
        }
        $io->newLine();

        $startTime = microtime(true);

        try {
            $this->commandBus->dispatch(new GenerateRecommendationsCommand(
                mode: $mode,
                userId: $userId,
            ));

            $duration = round(microtime(true) - $startTime, 2);
            $io->success(sprintf('Recommendation generation completed in %s seconds.', $duration));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Generation failed: %s', $e->getMessage()));
            $io->text(sprintf('Error: %s', $e->getTraceAsString()));

            return Command::FAILURE;
        }
    }
}
