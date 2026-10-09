<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\IssuedWebhookSecret;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Input and output the app:webhook:* commands share.
 */
final class WebhookConsole
{
    /**
     * The values of the repeatable --category option, or null when none was given.
     *
     * @return list<string>|null
     */
    public static function categories(InputInterface $input): ?array
    {
        $categories = array_values(array_map(strval(...), (array) $input->getOption('category')));

        return $categories === [] ? null : $categories;
    }

    /** Prints a newly issued secret, the only time it can be read, and the webhook's signing version. */
    public static function printSecret(SymfonyStyle $io, IssuedWebhookSecret $issued): void
    {
        $io->writeln('Signing secret, shown only once:');
        $io->writeln($issued->secret, OutputInterface::OUTPUT_RAW);
        $io->newLine();
        $io->text(sprintf(
            'Deliveries use signing version %d. Store the secret now; it cannot be shown again.',
            $issued->webhook->signingVersion,
        ));
    }
}
