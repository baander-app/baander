<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Local;

use App\Metadata\Application\DTO\Video\VideoArtwork;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoCredit;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Infrastructure\Video\VideoProviderAdapter;
use App\Shared\Domain\Model\Uuid;
use DOMElement;

final readonly class LocalVideoMetadata implements VideoProviderAdapter
{
    private const string AIRED = 'aired';

    public function __construct(
        private NfoReader $nfo,
        private MetadataProviderSwitches $switches,
    ) {}

    public function provider(): VideoProviderId
    {
        return VideoProviderId::Local;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return list<VideoCandidate>|VideoProviderProblem */
    public function search(VideoRequest $request): array|VideoProviderProblem
    {
        return $this->switches->enabled(MetadataProvider::Local) ? [] : VideoProviderProblem::Disabled;
    }

    public function identify(VideoRequest $request): VideoTitle|VideoProviderProblem|null
    {
        if (!$this->switches->enabled(MetadataProvider::Local)) {
            return VideoProviderProblem::Disabled;
        }
        $library = $request->libraryId;
        $id = $request->externalIds[VideoProviderId::Local->value] ?? $request->titleId?->toString();
        if ($library === null || $id === null) {
            return null;
        }
        $isSeries = $request->kind === VideoKind::Series;
        $kind = $isSeries ? VideoSidecarKind::SeriesNfo : VideoSidecarKind::MovieNfo;
        foreach ($request->sidecars as $sidecar) {
            if ($sidecar->kind !== $kind) {
                continue;
            }
            $root = $this->nfo->read($library, $sidecar)[0] ?? null;
            if ($root === null) {
                continue;
            }
            $candidate = self::candidate($id, $request->title, $root);
            if ($candidate !== null) {
                return $this->title($request, $sidecar, $root, $candidate, $library);
            }
        }

        return null;
    }

    private static function candidate(string $id, string $fallbackTitle, DOMElement $root): ?VideoCandidate
    {
        $title = NfoReader::text($root, 'title');
        $externalIds = self::identifiers($root);
        if ($title === null && $externalIds === []) {
            return null;
        }
        $premiered = self::date($root, 'premiered') ?? self::date($root, 'aired');
        $year = self::integer(NfoReader::text($root, 'year')) ?? ($premiered === null ? null : (int) substr($premiered, 0, 4));

        return new VideoCandidate($id, $title ?? $fallbackTitle, $year, $externalIds);
    }

    private function title(VideoRequest $request, VideoSidecar $sidecar, DOMElement $root, VideoCandidate $entry, Uuid $library): VideoTitle
    {
        $isSeries = $request->kind === VideoKind::Series;
        $poster = null;
        $backdrop = null;
        $directory = dirname($sidecar->path);
        foreach ($request->sidecars as $artwork) {
            if (dirname($artwork->path) !== $directory) {
                continue;
            }
            if ($artwork->kind === VideoSidecarKind::Poster) {
                $poster ??= new VideoArtwork($artwork->path, $library);
            } elseif ($artwork->kind === VideoSidecarKind::Backdrop) {
                $backdrop ??= new VideoArtwork($artwork->path, $library);
            }
        }

        return new VideoTitle(
            entry: $entry,
            overview: NfoReader::text($root, 'plot'),
            poster: $poster,
            backdrop: $backdrop,
            orderings: $isSeries ? [new VideoOrdering(self::AIRED, 'Aired', self::AIRED)] : [],
            seasons: $isSeries && $request->ordering === self::AIRED ? $this->seasons($request->sidecars, $library) : [],
            cast: self::cast($root),
        );
    }

    /**
     * @param list<VideoSidecar> $sidecars
     * @return list<VideoSeason>
     */
    private function seasons(array $sidecars, Uuid $library): array
    {
        $details = [];
        $posters = [];
        $thumbnails = [];
        foreach ($sidecars as $sidecar) {
            if ($sidecar->kind === VideoSidecarKind::SeasonNfo) {
                $root = $this->nfo->read($library, $sidecar)[0] ?? null;
                if ($root !== null) {
                    $number = self::integer(NfoReader::text($root, 'seasonnumber')) ?? $sidecar->seasonNumber;
                    if ($number !== null) {
                        $details[$number] ??= $root;
                    }
                }
            } elseif ($sidecar->kind === VideoSidecarKind::SeasonPoster && $sidecar->seasonNumber !== null) {
                $posters[$sidecar->seasonNumber] ??= new VideoArtwork($sidecar->path, $library);
            } elseif ($sidecar->kind === VideoSidecarKind::VideoThumbnail && $sidecar->videoStem !== null) {
                $thumbnails[dirname($sidecar->path)][$sidecar->videoStem] ??= new VideoArtwork($sidecar->path, $library);
            }
        }

        $episodes = [];
        foreach ($sidecars as $sidecar) {
            if ($sidecar->kind !== VideoSidecarKind::VideoNfo) {
                continue;
            }
            $still = $sidecar->videoStem === null ? null : ($thumbnails[dirname($sidecar->path)][$sidecar->videoStem] ?? null);
            foreach ($this->nfo->read($library, $sidecar) as $index => $root) {
                $season = self::integer(NfoReader::text($root, 'season'));
                $number = self::integer(NfoReader::text($root, 'episode'));
                $name = NfoReader::text($root, 'title');
                if ($season === null || $number === null || $number === 0 || $name === null) {
                    continue;
                }
                $episodes[$season][$number] ??= self::episode($root, $sidecar, $index, $season, $number, $name, $still);
            }
        }

        $numbers = array_keys($details + $episodes + $posters);
        sort($numbers, SORT_NUMERIC);
        $seasons = [];
        foreach ($numbers as $number) {
            $root = $details[$number] ?? null;
            $seasonEpisodes = $episodes[$number] ?? [];
            ksort($seasonEpisodes, SORT_NUMERIC);
            $seasons[] = new VideoSeason(
                number: $number,
                name: $root === null ? null : NfoReader::text($root, 'title'),
                overview: $root === null ? null : NfoReader::text($root, 'plot'),
                poster: $posters[$number] ?? null,
                episodes: array_values($seasonEpisodes),
            );
        }

        return $seasons;
    }

    private static function episode(
        DOMElement $root,
        VideoSidecar $sidecar,
        int $index,
        int $season,
        int $number,
        string $title,
        ?VideoArtwork $still,
    ): VideoEpisode {
        $externalIds = self::identifiers($root);
        $id = match (true) {
            isset($externalIds['tvdb']) => 'tvdb:' . $externalIds['tvdb'],
            isset($externalIds['tmdb']) => 'tmdb:' . $externalIds['tmdb'],
            isset($externalIds['imdb']) => 'imdb:' . $externalIds['imdb'],
            $sidecar->videoId !== null => 'video:' . $sidecar->videoId->toString() . ':' . $index,
            default => 's' . $season . 'e' . $number,
        };

        return new VideoEpisode(
            id: $id,
            number: $number,
            title: $title,
            airDate: self::date($root, 'aired') ?? self::date($root, 'premiered'),
            externalIds: $externalIds,
            overview: NfoReader::text($root, 'plot'),
            still: $still,
            cast: self::cast($root),
        );
    }

    /** @return list<VideoCredit>|null */
    private static function cast(DOMElement $root): ?array
    {
        $actors = NfoReader::elements($root, 'actor');
        if ($actors === []) {
            return null;
        }
        $cast = [];
        foreach ($actors as $index => $actor) {
            $name = NfoReader::text($actor, 'name');
            $identifiers = self::identifiers($actor, person: true);
            if ($name !== null && $identifiers !== []) {
                $cast[] = new VideoCredit(
                    identifiers: $identifiers,
                    name: $name,
                    category: 'cast',
                    role: 'actor',
                    job: 'Actor',
                    characterName: NfoReader::text($actor, 'role'),
                    billingOrder: self::integer(NfoReader::text($actor, 'order')) ?? $index,
                );
            }
        }

        return $cast;
    }

    /** @return array<string, string> */
    private static function identifiers(DOMElement $element, bool $person = false): array
    {
        $ids = [];
        foreach (NfoReader::elements($element, 'uniqueid') as $uniqueId) {
            $type = strtolower(NfoReader::attribute($uniqueId, 'type'));
            $provider = $type === 'thetvdb' ? 'tvdb' : $type;
            if (!in_array($provider, ['tvdb', 'tmdb', 'imdb'], true)) {
                continue;
            }
            $value = self::identifier($provider, NfoReader::text($uniqueId), $person);
            if ($value !== null) {
                $ids[$provider] ??= $value;
            }
        }
        foreach (['imdb', 'tmdb', 'tvdb'] as $provider) {
            $value = self::identifier($provider, NfoReader::text($element, $provider . 'id'), $person);
            if ($value !== null) {
                $ids[$provider] ??= $value;
            }
        }
        if (!$person) {
            return $ids;
        }
        $identifiers = [];
        foreach ($ids as $provider => $id) {
            $identifiers[$provider === 'imdb' ? 'imdb_name' : $provider . '_person'] = $id;
        }

        return $identifiers;
    }

    private static function identifier(string $provider, ?string $value, bool $person): ?string
    {
        if ($provider === 'imdb') {
            $pattern = $person ? '/^nm[0-9]{7,20}$/Di' : '/^tt[0-9]{7,20}$/Di';
            return $value !== null && preg_match($pattern, $value) === 1 ? strtolower($value) : null;
        }
        $id = self::integer($value);

        return $id !== null && $id > 0 ? (string) $id : null;
    }

    private static function integer(?string $value): ?int
    {
        if ($value === null || !ctype_digit($value)) {
            return null;
        }
        $number = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);

        return $number === false ? null : $number;
    }

    private static function date(DOMElement $root, string $field): ?string
    {
        $date = NfoReader::text($root, $field);
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
    }
}
