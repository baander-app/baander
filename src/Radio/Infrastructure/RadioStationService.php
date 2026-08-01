<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Application\Port\StationSyncPortInterface;
use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Domain\Model\RadioStation\Stream;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\Repository\RadioStation\RadioStationRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;

final class RadioStationService implements RadioStationPortInterface
{
    public function __construct(
        private readonly RadioStationRepositoryInterface $stationRepository,
        private readonly RadioSourceRepositoryInterface $sourceRepository,
        private readonly StationSyncPortInterface $syncAdapter,
    ) {
    }

    public function listStations(?string $countryCode = null, ?string $query = null): array
    {
        if ($query !== null) {
            $stations = $this->stationRepository->search($query, $countryCode);
        } elseif ($countryCode !== null) {
            $stations = $this->stationRepository->findByCountry($countryCode);
        } else {
            $qb = null;
            // Return all — not ideal for production but matches the interface
            $entities = [];
            // Use a broader approach: get all via search with empty query
            $stations = $this->stationRepository->search('');
        }

        return array_map($this->stationToArray(...), $stations);
    }

    public function getStation(Uuid $stationId): array
    {
        $station = $this->stationRepository->find($stationId);

        if ($station === null) {
            throw new RuntimeException('Station not found.');
        }

        return $this->stationToArray($station);
    }

    public function syncCountryStations(Uuid $sourceId, string $countryCode): int
    {
        $source = $this->sourceRepository->find($sourceId);

        if ($source === null) {
            throw new RuntimeException('Radio source not found.');
        }

        $rawStations = $this->syncAdapter->fetchStationsByCountry($countryCode);
        $count = 0;

        foreach ($rawStations as $raw) {
            $externalId = (string) ($raw['external_id'] ?? $raw['id'] ?? '');
            $existing = $this->stationRepository->findBySourceAndExternalId($sourceId, $externalId);

            $streams = array_map(fn (array $s) => new Stream(
                url: $s['url'],
                format: $s['format'] ?? 'unknown',
                bitrate: (int) ($s['bitrate'] ?? 0),
                reliability: (float) ($s['reliability'] ?? 1.0),
            ), $raw['streams'] ?? []);

            if ($existing !== null) {
                $existing->updateDetails(
                    name: $raw['name'] ?? $existing->getName(),
                    streams: $streams,
                    genres: $raw['genres'] ?? $existing->getGenres(),
                    tags: $raw['tags'] ?? $existing->getTags(),
                    language: $raw['language'] ?? $existing->getLanguage(),
                    logo: $raw['logo'] ?? $existing->getLogo(),
                    website: $raw['website'] ?? $existing->getWebsite(),
                    lastCheckedAt: new \DateTimeImmutable(),
                );
                $this->stationRepository->save($existing);
            } else {
                $station = RadioStation::create(
                    sourceId: $sourceId,
                    externalId: $externalId,
                    name: $raw['name'] ?? 'Unknown',
                    country: $countryCode,
                    streams: $streams,
                    language: $raw['language'] ?? null,
                    genres: $raw['genres'] ?? [],
                    tags: $raw['tags'] ?? [],
                    logo: $raw['logo'] ?? null,
                    website: $raw['website'] ?? null,
                );
                $this->stationRepository->save($station);
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function stationToArray(RadioStation $station): array
    {
        return [
            'id' => $station->getId()->toString(),
            'sourceId' => $station->getSourceId()->toString(),
            'externalId' => $station->getExternalId(),
            'name' => $station->getName(),
            'country' => $station->getCountry(),
            'language' => $station->getLanguage(),
            'genres' => $station->getGenres(),
            'tags' => $station->getTags(),
            'streams' => array_map(fn (Stream $s) => [
                'url' => $s->url,
                'format' => $s->format,
                'bitrate' => $s->bitrate,
                'reliability' => $s->reliability,
            ], $station->getStreams()),
            'logo' => $station->getLogo(),
            'website' => $station->getWebsite(),
            'lastCheckedAt' => $station->getLastCheckedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $station->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $station->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
