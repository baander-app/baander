<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\UpdateSystemSettingsCommand;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Domain\Model\Setting\SettingViolation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateSystemSettingsHandler
{
    public function __construct(
        private SystemSettings $settings,
        private SettingValueParser $parser,
        private SystemSettingStoreInterface $store,
    ) {
    }

    /**
     * Validates every value before writing any, then writes them together.
     *
     * @throws InvalidSettingValuesException when a key is unknown or a value is invalid
     */
    #[AsMessageHandler]
    public function __invoke(UpdateSystemSettingsCommand $command): void
    {
        $values = [];
        $violations = [];

        foreach ($command->values as $key => $input) {
            $key = (string) $key;
            try {
                $definition = $this->settings->definition($key);
            } catch (UnknownSettingException) {
                $violations[] = new SettingViolation($key, 'Unknown setting.');
                continue;
            }

            $result = $this->parser->parse($definition, $input);
            if ($result->violation !== null) {
                $violations[] = $result->violation;
                continue;
            }
            $values[$key] = $result->typedValue();
        }

        if ($violations !== []) {
            throw new InvalidSettingValuesException($violations);
        }

        if ($values !== []) {
            $this->store->save($values);
        }
    }
}
