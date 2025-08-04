<?php

namespace App\Jobs\Library\Music\Concerns;

use App\Models\Artist;

trait UpdatesArtistMetadata
{
    private function updateArtistMetadata(Artist $artist, array $data, string $source): array
    {
        $updateData = [];

        // Common field mappings - can be overridden by source-specific logic
        $fieldMappings = $this->getFieldMappings($source);

        foreach ($fieldMappings as $sourceField => $artistField) {
            $value = data_get($data, $sourceField);

            if ($value && $this->shouldUpdateField($artist, $artistField, $value)) {
                $updateData[$artistField] = $this->transformValue($artistField, $value, $source);
            }
        }

        // Handle complex fields that need special processing
        $updateData = array_merge($updateData, $this->processComplexFields($artist, $data, $source));

        if (!empty($updateData)) {
            $artist->update($updateData);

            $this->logger()->info('Artist metadata updated', [
                'artist_id' => $artist->id,
                'updated_fields' => array_keys($updateData),
                'source' => $source
            ]);
        }

        return $updateData;
    }

    private function shouldUpdateField(Artist $artist, string $field, $value): bool
    {
        return $this->forceUpdate || empty($artist->$field) || $this->isHigherQualityData($artist->$field, $value);
    }

    private function isHigherQualityData($existing, $new): bool
    {
        // Add logic to determine if new data is higher quality
        // For now, just check length as a simple heuristic
        return strlen($new) > strlen($existing);
    }

    private function transformValue(string $field, $value, string $source)
    {
        return match([$field, $source]) {
            ['type', 'musicbrainz'] => strtolower($value),
            ['gender', 'musicbrainz'] => strtolower($value),
            ['country', 'musicbrainz'] => $value[0] ?? $value, // Handle ISO codes array
            ['disambiguation', 'discogs'] => $this->extractDisambiguationFromProfile($value),
            default => $value
        };
    }

    private function extractDisambiguationFromProfile(string $profile): string
    {
        $firstSentence = strtok($profile, '.');
        return strlen($firstSentence) < 200 ? trim($firstSentence) : '';
    }

    abstract protected function getFieldMappings(string $source): array;
    abstract protected function processComplexFields(Artist $artist, array $data, string $source): array;
}