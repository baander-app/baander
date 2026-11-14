<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/metadata/match/reviews.
 *
 * Lists the albums the automatic matcher left for an admin, with the public IDs the match commands take.
 */
#[AsCommand(
    name: 'app:metadata:match:reviews',
    description: 'List the albums that need a MusicBrainz match review, the most recently checked first.',
)]
final class MusicBrainzMatchReviewsCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Albums per page, 1-%d', ListMatchReviewsQuery::MAX_LIMIT), (string) ListMatchReviewsQuery::DEFAULT_LIMIT)
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Continue from the cursor a previous page printed');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $page = $this->support->dispatch(new ListMatchReviewsQuery(
                limit: AdminCommandSupport::integerOption($input, 'limit') ?? ListMatchReviewsQuery::DEFAULT_LIMIT,
                cursor: AdminCommandSupport::stringOption($input, 'cursor'),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($page instanceof MatchReviewPage);

        $data = $page->toArray();
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        AdminCommandSupport::list(
            $input,
            $io,
            $data['items'],
            ['Album ID', 'Title', 'Reason', 'Checked', 'Best score'],
            static fn (array $review): array => [
                $review['albumPublicId'],
                $review['title'],
                $review['reason'],
                $review['checkedAt'],
                $review['bestScore'] ?? '-',
            ],
            'No album needs a review.',
        );

        if ($data['next_cursor'] !== null) {
            $io->text(sprintf('More albums follow. Continue with --cursor=%s', $data['next_cursor']));
        }

        return Command::SUCCESS;
    }
}
