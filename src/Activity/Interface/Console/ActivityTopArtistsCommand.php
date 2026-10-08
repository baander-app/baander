<?php

declare(strict_types=1);

namespace App\Activity\Interface\Console;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/activity/top-artists. */
#[AsCommand(
    name: 'app:activity:top-artists',
    description: 'List the most played artists over a range of days.',
)]
final class ActivityTopArtistsCommand extends Command
{
    public function __construct(
        private readonly ActivityAnalyticsPortInterface $analytics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ActivityAnalyticsOptions::configure($this, withLimit: true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $range = ActivityAnalyticsOptions::range($input);
            $artists = $this->analytics->getTopArtists($range->from, $range->to, ActivityAnalyticsOptions::limit($input));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            ActivityAnalyticsOptions::describe($io, $range);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            array_values($artists),
            ['Artist', 'Plays'],
            static fn (array $artist): array => [$artist['artist_name'], $artist['play_count']],
            'No artists were played in this range.',
        );
    }
}
