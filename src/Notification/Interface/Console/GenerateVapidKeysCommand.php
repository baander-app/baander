<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-vapid-keys',
    description: 'Generate VAPID keys for Web Push.',
)]
final class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keySet = VAPID::createVapidKeys();

        $io->title('VAPID Keys Generated');
        $io->text('Add these to your .env file:');
        $io->newLine();
        $io->text(sprintf('VAPID_PUBLIC_KEY=%s', $keySet['publicKey']));
        $io->text(sprintf('VAPID_PRIVATE_KEY=%s', $keySet['privateKey']));
        $io->newLine();
        $io->warning('Rotating keys will invalidate all existing push subscriptions.');

        return Command::SUCCESS;
    }
}
