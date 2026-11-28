<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\ValueObject\VideoName\EpisodeName;

final class EpisodeNameParser
{
    public function parse(string $relativePath): EpisodeName
    {
        $parts = explode('/', $relativePath);
        $stem = NameTokens::stem(array_pop($parts));
        $seriesFolder = $parts[0] ?? '';
        $season = $this->folderSeason($parts);
        $numbers = [];
        $numberText = NameTokens::withoutProviderTags($stem);
        if ($seriesFolder !== '') {
            $seriesTitle = NameTokens::title($seriesFolder);
            $titlePattern = preg_quote($seriesTitle, '/');
            $titlePattern = str_replace(' ', '[ ._]+', $titlePattern);
            $numberText = preg_replace('/^' . $titlePattern . '(?![\pL\pN])/iu', '', $numberText) ?? $numberText;
        }
        if (preg_match('/(?<![a-z0-9])s(\d{1,3})e(\d{1,4})((?:-?e\d{1,4})*)(?!\d)/i', $numberText, $match, PREG_OFFSET_CAPTURE) === 1) {
            $season = (int) $match[1][0];
            $numbers = $this->episodeNumbers((int) $match[2][0], $match[3][0]);
            $numberText = substr($numberText, $match[0][1] + strlen($match[0][0]));
        } elseif (preg_match('/(?<![a-z0-9])(\d{1,3})x(\d{1,4})(?![a-z0-9])/i', $numberText, $match, PREG_OFFSET_CAPTURE) === 1) {
            $season = (int) $match[1][0];
            $numbers = [(int) $match[2][0]];
            $numberText = substr($numberText, $match[0][1] + strlen($match[0][0]));
        }
        $date = $this->airDate($stem);
        $absolute = $this->bareNumber($numberText);
        if ($numbers === [] && $season !== null && $absolute !== null) {
            $numbers = [$absolute];
            $absolute = null;
        }
        $name = $seriesFolder !== '' ? $seriesFolder : $this->seriesName($stem);

        return new EpisodeName(
            seriesFolder: $seriesFolder,
            title: NameTokens::title($name),
            year: NameTokens::year($name),
            providerTags: NameTokens::providerTags($name . ' ' . $stem),
            seasonNumber: $season,
            episodeNumbers: $numbers,
            absoluteNumber: $absolute,
            airDate: $date,
            extraKind: NameTokens::extraKind(array_slice($parts, 1), $stem),
            sample: NameTokens::sample($stem),
        );
    }

    /** @param list<string> $directories */
    private function folderSeason(array $directories): ?int
    {
        foreach (array_slice($directories, 1) as $directory) {
            if (strcasecmp($directory, 'Specials') === 0) {
                return 0;
            }
            if (preg_match('/^season\s+(\d+)$/iD', $directory, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /** @return list<int> */
    private function episodeNumbers(int $first, string $suffix): array
    {
        $numbers = [$first];
        preg_match_all('/(-?)e(\d+)/i', $suffix, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $last = (int) $match[2];
            $previous = $numbers[array_key_last($numbers)];
            $next = $match[1] === '-' && $last > $previous ? range($previous + 1, $last) : [$last];
            array_push($numbers, ...$next);
        }

        return array_values(array_unique($numbers));
    }

    private function bareNumber(string $name): ?int
    {
        $name = preg_replace('/(?<!\d)\d{1,4}([.-])\d{1,2}\1\d{1,4}(?!\d)/', '', $name) ?? $name;
        preg_match_all('/(?<![\pL\pN])(\d{1,4})(?![\pL\pN])/u', $name, $matches);
        foreach ($matches[1] as $match) {
            $number = (int) $match;
            if ($number > 0 && ($number < 1900 || $number > 2099)) {
                return $number;
            }
        }

        return null;
    }

    private function airDate(string $name): ?string
    {
        if (preg_match('/(?<!\d)(\d{4})([.-])(\d{2})\2(\d{2})(?!\d)/', $name, $match) !== 1) {
            return null;
        }
        if (!checkdate((int) $match[3], (int) $match[4], (int) $match[1])) {
            return null;
        }

        return $match[1] . '-' . $match[3] . '-' . $match[4];
    }

    private function seriesName(string $stem): string
    {
        $name = preg_split('/(?:[ ._-]+)(?:s\d+e\d+|\d+x\d+|\d{4}[.-]\d{2}[.-]\d{2}|\d{1,3}(?:[ ._-]|$))/i', $stem, 2);

        return $name[0] ?? $stem;
    }
}
