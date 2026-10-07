<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Command\ResetSystemSettingCommand;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Service\SystemSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/** The CLI counterpart of DELETE /api/admin/settings/{key}. */
#[AsCommand(
    name: 'app:settings:reset',
    description: 'Reset a server-wide setting to its default.',
)]
final class SettingsResetCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
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
        $key = (string) $input->getArgument('key');

        try {
            $this->commandBus->dispatch(new ResetSystemSettingCommand($key));
        } catch (HandlerFailedException $e) {
            if (!$e->getPrevious() instanceof UnknownSettingException) {
                throw $e;
            }
            $io->error($e->getPrevious()->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s follows its default again: %s.', $key, SettingValueFormatter::format($this->settings->get($key))));

        return Command::SUCCESS;
    }
}
