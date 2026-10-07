<?php

declare(strict_types=1);

namespace App\UserPreference\Application\CommandHandler;

use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Command\ResetUserSettingCommand;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use App\UserPreference\Application\Service\UserSettingsReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ResetUserSettingHandler
{
    public function __construct(
        private UserSettingsReader $settings,
        private UserSettingStoreInterface $store,
    ) {
    }

    /**
     * @throws UnknownSettingException when no user setting has the key
     */
    #[AsMessageHandler]
    public function __invoke(ResetUserSettingCommand $command): void
    {
        $this->store->delete(Uuid::fromString($command->userId), $this->settings->definition($command->key)->key);
    }
}
