<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\RateLimiterAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rate-limiter:list',
    description: 'List every configured rate limiter with its effective configuration.',
)]
final class RateLimiterListCommand extends Command
{
    public function __construct(
        private readonly RateLimiterAdministrationInterface $rateLimiters,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->rateLimiters->list() as $limiter) {
            $rows[] = [
                $limiter->name,
                $limiter->policy,
                $limiter->limit,
                $limiter->interval ?? '',
                $limiter->cachePool,
                $limiter->description ?? '',
            ];
        }

        (new SymfonyStyle($input, $output))->table(
            ['Name', 'Policy', 'Limit', 'Interval', 'Cache pool', 'Description'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
