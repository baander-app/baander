<?php

declare(strict_types=1);

namespace App\UserPreference\Application\CommandHandler;

use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Command\SetUserSettingCommand;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use App\UserPreference\Application\Service\UserSettingsReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SetUserSettingHandler
{
    public function __construct(
        private UserSettingsReader $settings,
        private SettingValueParser $parser,
        private UserSettingStoreInterface $store,
    ) {
    }

    /**
     * @throws UnknownSettingException       when no user setting has the key
     * @throws InvalidSettingValuesException when the value is not allowed
     */
    #[AsMessageHandler]
    public function __invoke(SetUserSettingCommand $command): void
    {
        $definition = $this->settings->definition($command->key);
        $result = $this->parser->parse($definition, $command->value);
        if ($result->violation !== null) {
            throw new InvalidSettingValuesException([$result->violation]);
        }

        $this->store->save(Uuid::fromString($command->userId), $definition->key, $result->typedValue());
    }
}
