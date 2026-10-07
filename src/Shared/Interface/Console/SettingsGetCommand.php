<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Service\SystemSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shows one server-wide setting, including a stored value that is no longer allowed. */
#[AsCommand(
    name: 'app:settings:get',
    description: 'Show a server-wide setting: its value, default and stored value.',
)]
final class SettingsGetCommand extends Command
{
    public function __construct(
        private readonly SystemSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Setting key, for example transcode.max_bitrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $entry = $this->settings->entry((string) $input->getArgument('key'));
        } catch (UnknownSettingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Key' => $entry->definition->key],
            ['Value' => SettingValueFormatter::format($entry->value)],
            ['Default' => SettingValueFormatter::format($entry->definition->default)],
            ['Stored' => SettingValueFormatter::stored($entry)],
            ['Allowed' => $entry->definition->allowedValues === []
                ? $entry->definition->type->value
                : implode(', ', $entry->definition->allowedValues)],
            ['Enforced' => $entry->definition->enforced ? 'yes' : 'not yet'],
        );

        return Command::SUCCESS;
    }
}
