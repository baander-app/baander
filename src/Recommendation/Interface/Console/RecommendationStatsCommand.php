<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Console;

use App\Recommendation\Application\Port\RecommendationInsightsPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI counterpart of GET /api/admin/recommendations/coverage, /source-quality and
 * /freshness. With --json it prints the three `data` payloads under `coverage`,
 * `source_quality` and `freshness`.
 */
#[AsCommand(
    name: 'app:recommendation:stats',
    description: 'Show recommendation coverage, source quality and freshness.',
)]
final class RecommendationStatsCommand extends Command
{
    public function __construct(
        private readonly RecommendationInsightsPortInterface $insights,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $coverage = $this->insights->getCoverage();
        $sourceQuality = $this->insights->getSourceQuality();
        $freshness = $this->insights->getFreshness();

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, [
                'coverage' => $coverage,
                'source_quality' => $sourceQuality,
                'freshness' => $freshness,
            ]);
        }

        $io->section('Coverage');
        $io->definitionList(
            ['Tracks' => $coverage['total_tracks']],
            ['With recommendations' => $coverage['tracks_with_recommendations']],
            ['Without recommendations' => $coverage['tracks_without_recommendations']],
            ['Coverage' => sprintf('%s%%', $coverage['coverage_percentage'])],
        );

        $io->section('Source quality');
        $io->definitionList(['Average confidence score' => $sourceQuality['avg_confidence_score']]);
        if ($sourceQuality['by_source_type'] === []) {
            $io->text('No recommendations are stored.');
        } else {
            $io->table(
                ['Source type', 'Recommendations'],
                array_map(
                    static fn (int|string $type, mixed $count): array => [(string) $type, $count],
                    array_keys($sourceQuality['by_source_type']),
                    $sourceQuality['by_source_type'],
                ),
            );
        }

        $io->section('Freshness');
        $io->definitionList(
            ['Average age (seconds)' => $freshness['avg_age_seconds']],
            ['Last generated' => $freshness['last_generated_at'] ?? 'never'],
        );

        return Command::SUCCESS;
    }
}
