<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\ResetSystemSettingCommand;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SystemSettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ResetSystemSettingHandler
{
    public function __construct(
        private SystemSettings $settings,
        private SystemSettingStoreInterface $store,
    ) {
    }

    /**
     * @throws UnknownSettingException when no system setting has the key
     */
    #[AsMessageHandler]
    public function __invoke(ResetSystemSettingCommand $command): void
    {
        $this->store->delete($this->settings->definition($command->key)->key);
    }
}
