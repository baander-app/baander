<?php

namespace App\Jobs\Library\Music\Concerns;

use App\Models\Artist;
use App\Services\Metadata\ArtistFieldNormalizer;

trait UpdatesArtistMetadata
{
    private function updateArtistMetadata(Artist $artist, array $data, string $source): array
    {
        $updateData = [];
        $fieldMappings = $this->getFieldMappings($source);

        foreach ($fieldMappings as $sourceField => $artistField) {
            $value = data_get($data, $sourceField);

            if ($value && $this->shouldUpdateField($artist, $artistField, $value)) {
                // Normalize the value before updating the model
                $updateData[$artistField] = ArtistFieldNormalizer::normalize($artistField, $value, $source);
            }
        }

        $updateData = array_merge($updateData, $this->processComplexFields($artist, $data, $source));

        if (!empty($updateData)) {
            $artist->update($updateData);
            $this->getLogger()->info('Artist metadata updated', [
                'artist_id'      => $artist->id,
                'updated_fields' => array_keys($updateData),
                'source'         => $source,
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
        return strlen($new) > strlen($existing);
    }

    abstract protected function getFieldMappings(string $source): array;

    abstract protected function processComplexFields(Artist $artist, array $data, string $source): array;
}