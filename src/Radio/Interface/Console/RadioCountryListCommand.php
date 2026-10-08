<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/radio/countries. */
#[AsCommand(
    name: 'app:radio:country:list',
    description: 'List the countries the radio station directory offers, with their station counts.',
)]
final class RadioCountryListCommand extends Command
{
    public function __construct(
        private readonly CountrySubscriptionPortInterface $subscriptions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $countries = $this->subscriptions->listAvailableCountries();
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            $countries,
            ['Code', 'Name', 'Stations'],
            static fn (array $country): array => [
                $country['code'],
                $country['name'],
                (string) ($country['station_count'] ?? '-'),
            ],
            'The station directory offers no countries.',
        );
    }
}
