<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/radio/stations. */
#[AsCommand(
    name: 'app:radio:station:list',
    description: 'List the synced radio stations, optionally by country or search text.',
)]
final class RadioStationListCommand extends Command
{
    public function __construct(
        private readonly RadioStationPortInterface $stations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Only stations of this ISO 3166-1 alpha-2 country code, such as DK')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Only stations whose name matches this text');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $stations = $this->stations->listStations(self::filter($input, 'country'), self::filter($input, 'query'));
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            $stations,
            ['ID', 'Name', 'Country', 'Language', 'Genres', 'Streams'],
            static fn (array $station): array => [
                $station['id'],
                $station['name'],
                $station['country'] ?? '-',
                $station['language'] ?? '-',
                implode(', ', (array) ($station['genres'] ?? [])) ?: '-',
                (string) count((array) ($station['streams'] ?? [])),
            ],
            'No radio stations match.',
        );
    }

    /** The option's value, with an empty value meaning no filter, as the API treats an empty query parameter. */
    private static function filter(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
