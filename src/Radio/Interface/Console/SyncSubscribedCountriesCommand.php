<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Application\Port\StationSyncPortInterface;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:radio:sync',
    description: 'Sync stations for all subscribed countries.',
)]
final class SyncSubscribedCountriesCommand extends Command
{
    private const IPRD_SOURCE_NAME = 'IPRD';
    private const IPRD_SOURCE_TYPE = 'iprd';

    public function __construct(
        private readonly RadioSourcePortInterface $sourcePort,
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
        private readonly RadioSourceRepositoryInterface $sourceRepository,
        private readonly StationSyncPortInterface $syncAdapter,
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

            $source = RadioSource::create(
                name: self::IPRD_SOURCE_NAME,
                type: self::IPRD_SOURCE_TYPE,
                syncConfig: new SyncConfig(
                    syncUrl: 'https://iprd-org.github.io/iprd',
                    schedule: '0 */6 * * *',
                    config: [],
                ),
            );
            $this->sourceRepository->save($source);

            $io->success(sprintf('Created default IPRD source: %s', $source->getId()->toString()));
            $activeSource = [
                'id' => $source->getId()->toString(),
                'name' => $source->getName(),
                'isActive' => true,
            ];
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

        // Determine which countries to sync
        if ($filterCountry !== null) {
            // Explicit country filter — sync just that one
            $countries = [['countryCode' => $filterCountry]];
        } else {
            // Sync from all subscriptions across all users
            $subscriptions = $this->subscriptionPort->listSubscriptions(
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            );

            if (empty($subscriptions)) {
                // No subscriptions yet — sync all available countries from the source
                $io->note('No subscriptions found. Syncing all available countries from source...');
                $available = $this->syncAdapter->fetchCountries();
                $countries = array_map(fn (array $c) => ['countryCode' => $c['code']], $available);
            } else {
                $countries = $subscriptions;
            }
        }

        $io->title('Radio station sync');

        $totalSynced = 0;

        foreach ($countries as $entry) {
            $countryCode = $entry['countryCode'];

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
