<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use App\Catalog\Interface\Resource\VideoMatchReviewPageResource;
use App\Shared\Interface\Console\TerminalText;

#[AsCommand(name: 'app:video:match:reviews', description: 'List video match reviews and paused metadata providers.')]
final class VideoMatchReviewsCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page, 1-100', '50')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Continue from an issued review cursor')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Filter series, movie or file reviews')
            ->addOption('library', null, InputOption::VALUE_REQUIRED, 'Filter by library public ID or slug')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Filter by review reason');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $page = $this->matches->review(
                limit: AdminCommandSupport::integerOption($input, 'limit') ?? 50,
                cursor: AdminCommandSupport::stringOption($input, 'cursor'),
                kind: AdminCommandSupport::stringOption($input, 'kind'),
                library: AdminCommandSupport::stringOption($input, 'library'),
                reason: AdminCommandSupport::stringOption($input, 'reason'),
            );
            $data = VideoMatchReviewPageResource::from($page);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        AdminCommandSupport::list(
            $input,
            $io,
            $data['items'],
            ['Kind', 'Public ID', 'Title', 'Path', 'Library', 'Reason', 'Checked'],
            static fn (array $row): array => [$row['kind'], $row['publicId'], $row['title'], $row['path'] ?? '-', $row['libraryName'], $row['reason'], $row['checkedAt']],
            'No video title or file needs review.',
        );
        if ($data['pausedProviders'] !== []) {
            $rows = array_map(static fn (array $pause): array => [$pause['provider'], $pause['retryAt'], $pause['failureKind']], $data['pausedProviders']);
            $io->table(['Paused provider', 'Retry at', 'Failure'], TerminalText::literalRows($rows));
        }
        if ($data['nextCursor'] !== null) {
            $io->text('Continue with --cursor=' . $data['nextCursor']);
        }

        return Command::SUCCESS;
    }
}
