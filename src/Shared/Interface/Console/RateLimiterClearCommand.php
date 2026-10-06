<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\UnknownRateLimiterException;
use App\Shared\Application\Port\RateLimiterAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rate-limiter:clear',
    description: 'Clear the stored state of one rate limiter, or of all of them with --all.',
)]
final class RateLimiterClearCommand extends Command
{
    public function __construct(
        private readonly RateLimiterAdministrationInterface $rateLimiters,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Rate limiter to clear (see app:rate-limiter:list)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clear every configured rate limiter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $all = (bool) $input->getOption('all');

        if ($all === ($name !== null)) {
            $io->error('Pass either a rate limiter name or --all.');

            return Command::INVALID;
        }

        try {
            if ($all) {
                $cleared = $this->rateLimiters->clearAll();
                $io->success(sprintf('Cleared %d rate limiter(s): %s', count($cleared), implode(', ', $cleared)));

                return Command::SUCCESS;
            }

            $this->rateLimiters->clear((string) $name);
        } catch (UnknownRateLimiterException|RateLimiterClearFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Cleared rate limiter "%s".', $name));

        return Command::SUCCESS;
    }
}
