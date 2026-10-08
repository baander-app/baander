<?php

declare(strict_types=1);

namespace App\Media\Interface\Console;

use App\Media\Application\Port\MediaAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/media/storage-stats.
 */
#[AsCommand(
    name: 'app:image:stats',
    description: 'Show how many images are stored and how much space they use, by type.',
)]
final class ImageStatsCommand extends Command
{
    public function __construct(
        private readonly MediaAdminPortInterface $mediaAdmin,
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

        try {
            $stats = $this->mediaAdmin->getStorageStats();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $stats);
        }

        $rows = array_map(
            static fn (array $type): array => [$type['type'], $type['count'], self::size($type['size'])],
            $stats['byType'],
        );
        $rows[] = ['Total', $stats['totalImages'], self::size($stats['totalSize'])];

        $io->table(['Type', 'Images', 'Size'], $rows);

        return Command::SUCCESS;
    }

    private static function size(int $bytes): string
    {
        return sprintf('%s (%d bytes)', Helper::formatMemory($bytes), $bytes);
    }
}
