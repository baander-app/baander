<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use Psr\Log\LoggerInterface;

final class ArtistMetadataEnricher
{
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly DiscogsAdapter $discogs,
        private readonly ArtistPortInterface $artistService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enrich(Artist $artist, bool $forceUpdate = false): EnrichmentResult
    {
        $this->logger->info('Starting artist enrichment', [
            'artist_id' => $artist->getId(),
            'name' => $artist->getName(),
        ]);

        try {
            $data = $this->searchGeneral($artist);

            if ($data === null) {
                return EnrichmentResult::noMatch('general', 0.0);
            }

            return $this->applyData($artist, $data, $data['source'], $forceUpdate);
        } catch (\Throwable $e) {
            $this->logger->error('Artist enrichment failed', [
                'artist_id' => $artist->getId(),
                'error' => $e->getMessage(),
            ]);

            return EnrichmentResult::failure('general');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchGeneral(Artist $artist): ?array
    {
        // Try MusicBrainz
        $mbResult = $this->musicBrainz->searchArtist($artist->getName(), limit: 5);

        if ($mbResult->artists !== []) {
            $best = $mbResult->artists[0];
            $quality = min(1.0, $best->score / 100);

            return [
                'source' => 'musicbrainz',
                'quality' => $quality,
                'mbid' => $best->id,
                'name' => $best->name,
                'sortName' => $best->sortName,
                'type' => $best->type,
                'country' => $best->country,
                'disambiguation' => $best->disambiguation,
                'lifeSpanBegin' => $best->lifeSpanBegin,
                'lifeSpanEnd' => $best->lifeSpanEnd,
            ];
        }

        // Fallback to Discogs
        $discogsResult = $this->discogs->searchArtist($artist->getName());

        if ($discogsResult->artists !== []) {
            $best = $discogsResult->artists[0];
            $quality = min(1.0, $best->score / 100);

            return [
                'source' => 'discogs',
                'quality' => $quality,
                'discogsId' => (string) $best->id,
                'name' => $best->name,
                'profile' => $best->profile,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyData(Artist $artist, array $data, string $source, bool $forceUpdate): EnrichmentResult
    {
        $quality = $data['quality'] ?? 0.0;
        $hasIdentifiers = !empty($data['mbid']) || !empty($data['discogsId']);
        $threshold = $hasIdentifiers ? 0.6 : 0.7;

        if ($quality < $threshold) {
            $this->logger->debug('Artist enrichment rejected: quality below threshold', [
                'artist_id' => $artist->getId(),
                'quality' => $quality,
                'threshold' => $threshold,
            ]);

            return EnrichmentResult::noMatch($source, $quality);
        }

        // A field the operator locked keeps its value; enrichment applies the others.
        $candidates = [
            'name' => $forceUpdate ? ($data['name'] ?? null) : null,
            'country' => ($forceUpdate || $artist->getCountry() === null) ? ($data['country'] ?? null) : null,
            'type' => ($forceUpdate || $artist->getType() === null) ? ($data['type'] ?? null) : null,
            'disambiguation' => ($forceUpdate || $artist->getDisambiguation() === null) ? ($data['disambiguation'] ?? null) : null,
            'sortName' => ($forceUpdate || $artist->getSortName() === null) ? ($data['sortName'] ?? null) : null,
            'biography' => ($forceUpdate || $artist->getBiography() === null) ? ($data['profile'] ?? null) : null,
            'lifeSpanBegin' => ($forceUpdate || $artist->getLifeSpanBegin() === null) ? $this->parseDate($data['lifeSpanBegin'] ?? null) : null,
            'lifeSpanEnd' => ($forceUpdate || $artist->getLifeSpanEnd() === null) ? $this->parseDate($data['lifeSpanEnd'] ?? null) : null,
        ];
        $changes = array_filter(
            $candidates,
            static fn (mixed $value, string $field): bool => $value !== null && $value !== '' && !$artist->isFieldLocked($field),
            ARRAY_FILTER_USE_BOTH,
        );

        // Gender is not reliably available from the current providers.
        $artist->updateMetadata(...$changes);
        $updatedFields = array_keys($changes);

        // Apply identifiers
        if (!empty($data['mbid']) && ($forceUpdate || $artist->getMbid() === null)) {
            $artist->updateExternalIds(mbid: $data['mbid']);
            $updatedFields[] = 'mbid';
        }

        if (!empty($data['discogsId']) && ($forceUpdate || $artist->getDiscogsId() === null)) {
            $artist->updateExternalIds(discogsId: $data['discogsId']);
            $updatedFields[] = 'discogsId';
        }

        if (!empty($updatedFields)) {
            $this->artistService->save($artist);

            $this->logger->info('Artist enriched', [
                'artist_id' => $artist->getId(),
                'source' => $source,
                'quality' => $quality,
                'fields' => $updatedFields,
            ]);
        }

        return new EnrichmentResult(
            success: true,
            source: $source,
            qualityScore: $quality,
            updatedFields: $updatedFields,
            identifiersUpdated: !empty(array_intersect(['mbid', 'discogsId'], $updatedFields)),
        );
    }

    private function parseDate(?string $date): ?\DateTimeInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        // MusicBrainz dates can be YYYY, YYYY-MM, or YYYY-MM-DD
        if (preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $date, $matches)) {
            $year = (int) $matches[1];
            $month = isset($matches[2]) ? (int) $matches[2] : 1;
            $day = isset($matches[3]) ? (int) $matches[3] : 1;

            if (checkdate($month, $day, $year)) {
                return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            }

            return new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        }

        return null;
    }
}
