<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/debug/workers.
 */
#[AsCommand(
    name: 'app:server:workers',
    description: 'Show the web server\'s HTTP worker, task worker and transcoding pool statistics.',
)]
final class ServerWorkersCommand extends Command
{
    private const array SECTIONS = [
        'http_workers' => 'HTTP workers',
        'task_workers' => 'Task workers',
        'user_workers' => 'User workers',
        'transcode_pool' => 'Transcoding pool',
    ];

    public function __construct(
        private readonly ServerDiagnosticsInterface $diagnostics,
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
            $workers = $this->diagnostics->workers();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $workers);
        }

        foreach (self::SECTIONS as $key => $title) {
            $values = $workers[$key] ?? null;
            if (!is_array($values)) {
                continue;
            }
            $io->section($title);
            $io->table(['Figure', 'Value'], array_map(
                static fn (string|int $name, mixed $value): array => [str_replace('_', ' ', (string) $name), ServerWorkerReport::scalar($value)],
                array_keys($values),
                array_values($values),
            ));
        }

        return Command::SUCCESS;
    }
}
