<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\DTO\RadioStationPage;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
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
    description: 'List the synced radio stations, optionally by country or search text, one page at a time or all of them.',
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
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Only stations whose name contains this text')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Stations per page, at most %d', RadioStationPortInterface::MAX_LIMIT), (string) RadioStationPortInterface::DEFAULT_LIMIT)
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Stations to skip', '0')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every matching station, read page by page; ignores --limit and --offset');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $country = self::filter($input, 'country');
        $query = self::filter($input, 'query');

        try {
            if ($input->getOption('all')) {
                $stations = $this->every($country, $query);
                $summary = null;
            } else {
                $offset = self::offset($input);
                $page = $this->stations->listStations($country, $query, [], self::limit($input), $offset);
                $stations = $page->stations;
                $summary = self::summary($page, $offset);
            }
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $exitCode = AdminCommandSupport::list(
            $input,
            $io,
            $stations,
            ['ID', 'Name', 'Country', 'Language', 'Genres', 'Streams'],
            static fn (array $station): array => [
                $station['publicId'],
                $station['name'],
                $station['country'] ?? '-',
                $station['language'] ?? '-',
                implode(', ', (array) ($station['genres'] ?? [])) ?: '-',
                (string) count((array) ($station['streams'] ?? [])),
            ],
            'No radio stations match.',
        );
        if ($summary !== null && !AdminCommandSupport::wantsJson($input)) {
            $io->text($summary);
        }

        return $exitCode;
    }

    /**
     * Every matching station, read through the API's pages.
     *
     * @return list<array<string, mixed>>
     */
    private function every(?string $country, ?string $query): array
    {
        $stations = [];
        do {
            $page = $this->stations->listStations($country, $query, [], RadioStationPortInterface::MAX_LIMIT, count($stations));
            array_push($stations, ...$page->stations);
        } while ($page->stations !== [] && count($stations) < $page->total);

        return $stations;
    }

    /** Which stations of how many the page shows, or null when nothing matched. */
    private static function summary(RadioStationPage $page, int $offset): ?string
    {
        if ($page->stations === []) {
            return $page->total > 0 ? sprintf('No stations after offset %d; %d match.', $offset, $page->total) : null;
        }

        return sprintf('Stations %d-%d of %d.', $offset + 1, $offset + count($page->stations), $page->total);
    }

    /** @throws InvalidInputException for a limit the API refuses with 400 */
    private static function limit(InputInterface $input): int
    {
        $limit = AdminCommandSupport::integerOption($input, 'limit') ?? RadioStationPortInterface::DEFAULT_LIMIT;
        if ($limit < 1 || $limit > RadioStationPortInterface::MAX_LIMIT) {
            throw new InvalidInputException(sprintf('--limit must be between 1 and %d.', RadioStationPortInterface::MAX_LIMIT));
        }

        return $limit;
    }

    /** @throws InvalidInputException for an offset the API refuses with 400 */
    private static function offset(InputInterface $input): int
    {
        $offset = AdminCommandSupport::integerOption($input, 'offset') ?? 0;
        if ($offset < 0) {
            throw new InvalidInputException('--offset must be 0 or more.');
        }

        return $offset;
    }

    /** The option's value, with an empty value meaning no filter, as the API treats an empty query parameter. */
    private static function filter(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
