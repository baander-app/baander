<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:radio:sync',
    description: 'Sync stations for every country a user subscribes to.',
)]
final class SyncSubscribedCountriesCommand extends Command
{
    private const IPRD_SOURCE_NAME = 'IPRD';
    private const IPRD_SOURCE_TYPE = 'iprd';

    public function __construct(
        private readonly RadioSourcePortInterface $sourcePort,
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
        private readonly RadioStationPortInterface $stationPort,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without syncing')
            ->addOption('country', 'c', InputOption::VALUE_OPTIONAL, 'Sync only a specific country code')
            ->addOption('init', null, InputOption::VALUE_NONE, 'Create the default IPRD source if none exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $filterCountry = $input->getOption('country');

        $sources = $this->sourcePort->listSources();

        if (empty($sources)) {
            if (!$input->getOption('init')) {
                $io->warning('No radio sources configured. Run with --init to create the default IPRD source.');

                return Command::SUCCESS;
            }

            $activeSource = $this->sourcePort->createSource(
                self::IPRD_SOURCE_NAME,
                self::IPRD_SOURCE_TYPE,
                'https://iprd-org.github.io/iprd',
                [],
                '0 */6 * * *',
            );

            $io->success(sprintf('Created default IPRD source: %s', $activeSource['id']));
        } else {
            $activeSource = null;
            foreach ($sources as $source) {
                if ($source['isActive'] ?? false) {
                    $activeSource = $source;
                    break;
                }
            }

            if ($activeSource === null) {
                $io->warning('No active radio source found.');

                return Command::SUCCESS;
            }
        }

        $sourceId = Uuid::fromString($activeSource['id']);

        if ($filterCountry !== null) {
            $countries = [$filterCountry];
        } else {
            $countries = $this->subscriptionPort->listSubscribedCountryCodes();

            if ($countries === []) {
                $io->note('No user subscribes to a country, so there is nothing to sync. Use --country to sync one country.');

                return Command::SUCCESS;
            }
        }

        $io->title('Radio station sync');

        $totalSynced = 0;

        foreach ($countries as $countryCode) {
            if ($dryRun) {
                $io->text(sprintf('Would sync: %s (source: %s)', $countryCode, $activeSource['name']));
                continue;
            }

            $io->text(sprintf('Syncing stations for %s...', $countryCode));

            $count = $this->stationPort->syncCountryStations($sourceId, $countryCode);

            $io->text(sprintf('  Synced %d stations for %s', $count, $countryCode));
            $totalSynced += $count;
        }

        if ($dryRun) {
            $io->note('Dry run — no stations were synced.');
        } else {
            $io->success(sprintf('Sync complete. Total stations synced: %d', $totalSynced));
        }

        return Command::SUCCESS;
    }
}
