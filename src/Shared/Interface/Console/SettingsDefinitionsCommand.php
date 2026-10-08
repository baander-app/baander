<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Interface\Resource\SettingDefinitionResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The CLI counterpart of GET /api/admin/settings/definitions. */
#[AsCommand(
    name: 'app:settings:definitions',
    description: 'List the definition of every setting, server-wide and per-user.',
)]
final class SettingsDefinitionsCommand extends Command
{
    private const string SCOPE_OPTION = 'scope';

    public function __construct(
        private readonly SettingDefinitionRegistry $definitions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(self::SCOPE_OPTION, null, InputOption::VALUE_REQUIRED, 'Only the system or the user settings');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scope = $input->getOption(self::SCOPE_OPTION);
        if ($scope === null) {
            $definitions = $this->definitions->all();
        } else {
            $scope = SettingScope::tryFrom((string) $scope);
            if ($scope === null) {
                $io->getErrorStyle()->error('The --scope option must be system or user.');

                return Command::INVALID;
            }
            $definitions = $this->definitions->forScope($scope);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            SettingDefinitionResource::collection($definitions),
            ['Key', 'Scope', 'Type', 'Default', 'Allowed', 'Follows', 'Edit role', 'Enforced'],
            static fn (array $definition): array => [
                $definition['key'],
                $definition['scope'],
                $definition['type'],
                SettingValueFormatter::format($definition['default']),
                self::allowed($definition),
                $definition['fallbackKey'] ?? '-',
                $definition['editRole'],
                $definition['enforced'] ? 'yes' : 'not yet',
            ],
            'No settings are defined.',
        );
    }

    /** @param array<string, mixed> $definition */
    private static function allowed(array $definition): string
    {
        /** @var list<array{value: int|string, label: string}> $options */
        $options = $definition['options'];
        if ($options !== []) {
            return implode(', ', array_map(static fn (array $option): string => (string) $option['value'], $options));
        }
        if ($definition['min'] !== null || $definition['max'] !== null) {
            return sprintf('%s to %s', $definition['min'] ?? '-', $definition['max'] ?? '-');
        }

        return 'any ' . $definition['type'];
    }
}
