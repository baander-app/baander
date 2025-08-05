<?php

namespace App\Jobs\Library\Music\Concerns;

use App\Models\Album;
use App\Services\Metadata\AlbumFieldNormalizer;

trait UpdatesAlbumMetadata
{
    private function updateAlbumMetadata(Album $album, array $data, string $source): array
    {
        $updateData = [];
        $fieldMappings = $this->getAlbumFieldMappings($source);

        foreach ($fieldMappings as $sourceField => $albumField) {
            $value = data_get($data, $sourceField);

            if ($value && $this->shouldUpdateAlbumField($album, $albumField, $value)) {
                $updateData[$albumField] = AlbumFieldNormalizer::normalize($albumField, $value, $source);
            }
        }

        // Handle complex fields that need special processing
        $updateData = array_merge($updateData, $this->processComplexAlbumFields($album, $data, $source));

        if (!empty($updateData)) {
            $album->update($updateData);

            $this->getLogger()->info('Album metadata updated', [
                'album_id' => $album->id,
                'updated_fields' => array_keys($updateData),
                'source' => $source
            ]);
        }

        return $updateData;
    }

    private function shouldUpdateAlbumField(Album $album, string $field, $value): bool
    {
        return $this->forceUpdate || empty($album->$field) || $this->isHigherQualityAlbumData($album->$field, $value);
    }

    private function isHigherQualityAlbumData($existing, $new): bool
    {
        // For strings, longer is generally better quality
        if (is_string($existing) && is_string($new)) {
            return strlen($new) > strlen($existing);
        }

        // For years, more recent data might be more accurate
        if (is_numeric($existing) && is_numeric($new)) {
            return abs($new - date('Y')) <= abs($existing - date('Y'));
        }

        return strlen((string) $new) > strlen((string) $existing);
    }

    private function getAlbumFieldMappings(string $source): array
    {
        return match($source) {
            'musicbrainz' => [
                'title' => 'title',
                'date' => 'year',
                'id' => 'mbid',
            ],
            'discogs' => [
                'title' => 'title',
                'year' => 'year',
                'id' => 'discogs_id',
            ],
            default => []
        };
    }

    private function processComplexAlbumFields(Album $album, array $data, string $source): array
    {
        $updateData = [];

        // Handle release date extraction for year
        if (isset($data['release-date']) && !isset($updateData['year'])) {
            $year = AlbumFieldNormalizer::normalize('year', $data['release-date'], $source);
            if ($year && $this->shouldUpdateAlbumField($album, 'year', $year)) {
                $updateData['year'] = $year;
            }
        }

        return $updateData;
    }

    abstract protected function getLogger();
}