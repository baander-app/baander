<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Service\SystemSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The CLI counterpart of GET /api/admin/settings. */
#[AsCommand(
    name: 'app:settings:list',
    description: 'List every server-wide setting with its value, default and enforcement.',
)]
final class SettingsListCommand extends Command
{
    public function __construct(
        private readonly SystemSettings $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->settings->entries() as $entry) {
            $rows[] = [
                $entry->definition->key,
                SettingValueFormatter::format($entry->value),
                SettingValueFormatter::format($entry->definition->default),
                SettingValueFormatter::stored($entry),
                $entry->definition->enforced ? 'yes' : 'not yet',
            ];
        }

        (new SymfonyStyle($input, $output))->table(['Key', 'Value', 'Default', 'Stored', 'Enforced'], $rows);

        return Command::SUCCESS;
    }
}
