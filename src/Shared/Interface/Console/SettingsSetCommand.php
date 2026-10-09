<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Command\UpdateSystemSettingsCommand;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Interface\Resource\SystemSettingResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/** The CLI counterpart of PATCH /api/admin/settings. */
#[AsCommand(
    name: 'app:settings:set',
    description: 'Set a server-wide setting.',
)]
final class SettingsSetCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly SystemSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Setting key, for example transcode.max_bitrate')
            ->addArgument('value', InputArgument::REQUIRED, 'New value, for example true, 192 or da');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getArgument('key');

        try {
            $this->commandBus->dispatch(new UpdateSystemSettingsCommand([$key => (string) $input->getArgument('value')]));
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();
            if (!$cause instanceof InvalidSettingValuesException) {
                throw $e;
            }
            foreach ($cause->violations as $violation) {
                $io->getErrorStyle()->error(sprintf('%s: %s', $violation->key, $violation->message));
            }

            return Command::FAILURE;
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, SystemSettingResource::collection($this->settings->entries()));
        }

        $io->success(sprintf('%s is now %s.', $key, SettingValueFormatter::format($this->settings->get($key))));

        return Command::SUCCESS;
    }
}
