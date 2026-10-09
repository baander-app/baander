<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\LoginBlock\DeleteAllLoginBlocksCommand;
use App\Auth\Application\Command\LoginBlock\DeleteLoginBlockCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/admin/login-blocks/{id} and DELETE /api/admin/login-blocks.
 *
 * The API answers both with 204 and no body, so with `--json` the command prints nothing.
 */
#[AsCommand(
    name: 'app:login-block:delete',
    description: 'Remove one login block, or every block with --all.',
)]
final class LoginBlockDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The UUID of the block to remove, as app:login-block:list shows it')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Remove every block instead of one');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');
        $all = $input->getOption('all') === true;

        if (is_string($id) === $all) {
            $io->getErrorStyle()->error('Give either a block ID or --all.');

            return Command::INVALID;
        }

        if ($all) {
            return $this->deleteAll($input, $io);
        }

        try {
            $this->support->dispatch(new DeleteLoginBlockCommand((string) $id));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Login block "%s" removed.', $id));
        }

        return Command::SUCCESS;
    }

    private function deleteAll(InputInterface $input, SymfonyStyle $io): int
    {
        $refused = AdminCommandSupport::confirm($input, $io, 'Remove every login block? This cannot be undone.');
        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->support->dispatch(new DeleteAllLoginBlocksCommand());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success('Every login block was removed.');
        }

        return Command::SUCCESS;
    }
}
