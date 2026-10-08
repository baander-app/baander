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

/** The CLI counterpart of GET /api/admin/activity/top-tracks. */
#[AsCommand(
    name: 'app:activity:top-tracks',
    description: 'List the most played tracks over a range of days.',
)]
final class ActivityTopTracksCommand extends Command
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
            $tracks = $this->analytics->getTopTracks($range->from, $range->to, ActivityAnalyticsOptions::limit($input));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            ActivityAnalyticsOptions::describe($io, $range);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            array_values($tracks),
            ['Track', 'Artist', 'Album', 'Plays'],
            static fn (array $track): array => [
                $track['track_name'],
                $track['artist_name'] ?? '-',
                $track['album_name'] ?? '-',
                $track['play_count'],
            ],
            'No tracks were played in this range.',
        );
    }
}
