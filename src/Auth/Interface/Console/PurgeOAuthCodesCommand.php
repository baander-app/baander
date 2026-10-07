<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\PurgeExpiredOAuthCodesCommand;
use App\Auth\Application\DTO\PurgedOAuthCodesDTO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'app:oauth:purge-codes',
    description: 'Delete OAuth authorization codes and device codes that expired more than an hour ago.',
)]
final class PurgeOAuthCodesCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Runs the same purge as the daily scheduled job. An expired device code is kept
            for one hour, so a device that is still polling learns that its code expired.
            Codes without an expiry are never deleted.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->commandBus->dispatch(new PurgeExpiredOAuthCodesCommand())
                ->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $exception) {
            $io->error(sprintf('The purge failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if (!$result instanceof PurgedOAuthCodesDTO) {
            $io->error('The purge returned no result.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Deleted %d authorization code(s) and %d device code(s) that expired before %s.',
            $result->authorizationCodes,
            $result->deviceCodes,
            $result->expiredBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C'),
        ));

        return Command::SUCCESS;
    }
}
