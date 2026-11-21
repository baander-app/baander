<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MusicBrainzArtistLinks
{
    /**
     * @param list<array<array-key, mixed>> $relationships
     * @return array<string, string>
     */
    public static function fromRelationships(array $relationships): array
    {
        $claims = [];
        foreach ($relationships as $relationship) {
            $url = $relationship['url']['resource'] ?? null;
            if (!is_string($url) || ($relationship['ended'] ?? false) === true) {
                continue;
            }
            $parts = parse_url($url);
            if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
                || isset($parts['user']) || isset($parts['port'])) {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            $path = $parts['path'] ?? '';
            $type = $relationship['type'] ?? null;
            if ($type === 'IMDb' && in_array($host, ['imdb.com', 'www.imdb.com'], true)
                && preg_match('~^/name/(nm[0-9]{7,20})/?$~D', $path, $matches) === 1) {
                $claims['imdb_name'][$matches[1]] = $matches[1];
            }
            if ($type === 'apple music' && $host === 'music.apple.com'
                && preg_match('~^/(?:[a-z]{2}/)?artist/(?:[^/]+/)?([1-9][0-9]{0,30})/?$~D', $path, $matches) === 1) {
                $claims['apple_music_artist'][$matches[1]] = $matches[1];
            }
        }

        $verified = [];
        foreach ($claims as $namespace => $ids) {
            if (count($ids) === 1) {
                $verified[$namespace] = reset($ids);
            }
        }

        return $verified;
    }
}
