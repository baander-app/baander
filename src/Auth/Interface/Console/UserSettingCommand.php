<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\AdminUserSettings;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Interface\Console\SettingValueFormatter;
use App\UserPreference\Application\Port\UserSettingView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI counterpart of GET /api/admin/users/{id}/settings and of PUT and
 * DELETE on /api/admin/users/{id}/settings/{key}. Changes are logged with
 * the actor "cli".
 */
#[AsCommand(
    name: 'app:user:setting',
    description: "Show, set or reset a user's setting, such as their email language.",
)]
final class UserSettingCommand extends Command
{
    private const array ACTIONS = ['get', 'set', 'reset'];

    public function __construct(
        private readonly AdminUserSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'get, set or reset')
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID')
            ->addArgument('key', InputArgument::OPTIONAL, 'Setting key, for example language; get lists every setting without one')
            ->addArgument('value', InputArgument::OPTIONAL, 'New value for set, for example da')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>%command.full_name% get alice@baander.app</info>
                  <info>%command.full_name% get alice@baander.app language</info>
                  <info>%command.full_name% set alice@baander.app language da</info>
                  <info>%command.full_name% reset alice@baander.app language</info>

                A stored value that is no longer allowed is shown and marked (invalid);
                the user gets the value after a reset until it is changed.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $identifier = (string) $input->getArgument('identifier');
        $key = $input->getArgument('key');
        $key = is_string($key) ? $key : null;
        $value = $input->getArgument('value');

        if (!in_array($action, self::ACTIONS, true)) {
            $io->error(sprintf('Unknown action "%s"; use get, set or reset.', $action));

            return Command::FAILURE;
        }
        if ($key === null && $action !== 'get') {
            $io->error(sprintf('%s needs a setting key.', ucfirst($action)));

            return Command::FAILURE;
        }
        if ($action === 'set' && !is_string($value)) {
            $io->error('Set needs a value.');

            return Command::FAILURE;
        }

        try {
            return match ($action) {
                'get' => $key === null ? $this->list($io, $identifier) : $this->show($io, $identifier, $key),
                'set' => $this->changed($io, $identifier, $this->settings->set(AdminUserSettings::CLI_ACTOR, $identifier, (string) $key, $value)),
                'reset' => $this->changed($io, $identifier, $this->settings->reset(AdminUserSettings::CLI_ACTOR, $identifier, (string) $key)),
            };
        } catch (UserNotFoundException|UnknownSettingException $e) {
            $io->error($e->getMessage());
        } catch (InvalidSettingValuesException $e) {
            foreach ($e->violations as $violation) {
                $io->error(sprintf('%s: %s', $violation->key, $violation->message));
            }
        }

        return Command::FAILURE;
    }

    private function list(SymfonyStyle $io, string $identifier): int
    {
        $io->table(
            ['Key', 'Value', 'Source', 'Stored'],
            array_map(
                static fn (UserSettingView $setting): array => [
                    $setting->key,
                    SettingValueFormatter::format($setting->value),
                    $setting->source,
                    self::stored($setting),
                ],
                $this->settings->settings($identifier),
            ),
        );

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, string $identifier, string $key): int
    {
        $setting = $this->settings->setting($identifier, $key);

        $io->definitionList(
            ['Key' => $setting->key],
            ['Value' => SettingValueFormatter::format($setting->value)],
            ['Source' => $setting->source],
            ['Stored' => self::stored($setting)],
            ['After reset' => SettingValueFormatter::format($setting->resetValue)],
            ['Allowed' => $setting->options === []
                ? $setting->type
                : implode(', ', array_map(static fn (array $option): string => (string) $option['value'], $setting->options))],
            ['User may change' => $setting->userEditable ? 'yes' : 'no'],
        );

        return Command::SUCCESS;
    }

    private function changed(SymfonyStyle $io, string $identifier, UserSettingView $setting): int
    {
        $io->success(sprintf(
            '%s of "%s" is now %s (%s).',
            $setting->key,
            $identifier,
            SettingValueFormatter::format($setting->value),
            $setting->source,
        ));

        return Command::SUCCESS;
    }

    private static function stored(UserSettingView $setting): string
    {
        if ($setting->storedValue === null) {
            return '(none)';
        }

        return SettingValueFormatter::format($setting->storedValue) . ($setting->storedValueValid ? '' : ' (invalid)');
    }
}
