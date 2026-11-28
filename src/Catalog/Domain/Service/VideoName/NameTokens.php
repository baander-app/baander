<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Domain\ValueObject\VideoName\ProviderTag;

final class NameTokens
{
    private const string PROVIDER_TAG = '/(?:\{(tvdb|tmdb|imdb)-(\d+|tt\d+)\}|\[(tvdb|tmdb|imdb)id-(\d+|tt\d+)\])/i';

    public static function stem(string $path): string
    {
        return preg_replace('/\.[a-z0-9]{2,5}$/iD', '', basename($path)) ?? basename($path);
    }

    public static function withoutProviderTags(string $name): string
    {
        return preg_replace(self::PROVIDER_TAG, '', $name) ?? $name;
    }

    /** @return list<ProviderTag> */
    public static function providerTags(string $name): array
    {
        preg_match_all(self::PROVIDER_TAG, $name, $matches, PREG_SET_ORDER);
        $tags = [];
        foreach ($matches as $match) {
            $provider = strtolower($match[1] !== '' ? $match[1] : $match[3]);
            $id = strtolower($match[2] !== '' ? $match[2] : $match[4]);
            if (($provider === 'imdb') !== str_starts_with($id, 'tt')) {
                continue;
            }
            $tags[$provider . ':' . $id] = new ProviderTag($provider, $id);
        }

        return array_values($tags);
    }

    public static function year(string $name): ?int
    {
        if (preg_match('/[\[(]((?:19|20)\d{2})[\])]/', $name, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    public static function title(string $name): string
    {
        $name = self::withoutProviderTags($name);
        $name = preg_replace('/[\[(](?:19|20)\d{2}[\])]/', '', $name) ?? $name;
        $name = preg_replace('/[._]+/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name, " \t\n\r\0\x0B-");
    }

    public static function sample(string $stem): bool
    {
        return preg_match('/(?<![\pL\pN])sample(?![\pL\pN])/iu', $stem) === 1;
    }

    public static function extraFolder(string $folder): ?ExtraKind
    {
        return match (strtolower($folder)) {
            'behind the scenes' => ExtraKind::BehindTheScenes,
            'deleted scenes' => ExtraKind::DeletedScene,
            'featurettes' => ExtraKind::Featurette,
            'interviews' => ExtraKind::Interview,
            'scenes' => ExtraKind::Scene,
            'shorts' => ExtraKind::Short,
            'trailers' => ExtraKind::Trailer,
            'clips' => ExtraKind::Clip,
            'samples' => ExtraKind::Sample,
            'theme-music' => ExtraKind::ThemeMusic,
            'backdrops' => ExtraKind::Backdrop,
            'other', 'extras' => ExtraKind::Other,
            default => null,
        };
    }

    /** @param list<string> $directories */
    public static function extraKind(array $directories, string $stem): ?ExtraKind
    {
        foreach ($directories as $directory) {
            $kind = self::extraFolder($directory);
            if ($kind !== null) {
                return $kind;
            }
        }
        if (preg_match('/(?:^|[ ._-])(trailer|sample)$/iD', $stem, $match) === 1) {
            return strtolower($match[1]) === 'trailer' ? ExtraKind::Trailer : ExtraKind::Sample;
        }
        if (preg_match('/-(behindthescenes|deletedscene|deleted|featurette|interview|scene|short|clip|other|extra)$/iD', $stem, $match) !== 1) {
            return null;
        }

        return match (strtolower($match[1])) {
            'behindthescenes' => ExtraKind::BehindTheScenes,
            'deleted', 'deletedscene' => ExtraKind::DeletedScene,
            'featurette' => ExtraKind::Featurette,
            'interview' => ExtraKind::Interview,
            'scene' => ExtraKind::Scene,
            'short' => ExtraKind::Short,
            'clip' => ExtraKind::Clip,
            default => ExtraKind::Other,
        };
    }
}
