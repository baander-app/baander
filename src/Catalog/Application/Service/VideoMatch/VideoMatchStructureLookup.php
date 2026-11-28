<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;

final readonly class VideoMatchStructureLookup
{
    public function __construct(private VideoMetadataPortInterface $providers) {}

    /** Null means the provider did not offer an ordering for a new match. */
    public function resolve(
        VideoProviderId $provider,
        VideoRequest $request,
        VideoTitle $metadata,
        bool $allowDefault,
    ): ResolvedVideoMatch|VideoProviderProblem|null {
        $ordering = $request->ordering;
        if ($request->kind !== VideoKind::Series) {
            return new ResolvedVideoMatch($metadata, $ordering);
        }
        if (!$metadata->hasOrdering($ordering)) {
            if (!$allowDefault) {
                return new ResolvedVideoMatch($metadata, $ordering);
            }
            if ($ordering === 'aired' || !$metadata->hasOrdering('aired')) {
                return null;
            }
            $default = $this->identifyAired($provider, $request, $metadata->entry->id);
            if ($default instanceof VideoProviderProblem) {
                return $default;
            }

            return new ResolvedVideoMatch($default, 'aired');
        }
        if ($ordering === 'aired' || !$metadata->hasOrdering('aired') || !self::needsAbsoluteNumbers($metadata)) {
            return new ResolvedVideoMatch($metadata, $ordering);
        }
        $aired = $this->identifyAired($provider, $request, $metadata->entry->id);

        return $aired instanceof VideoProviderProblem ? $aired : new ResolvedVideoMatch($metadata, $ordering, $aired);
    }

    private function identifyAired(VideoProviderId $provider, VideoRequest $request, string $id): VideoTitle|VideoProviderProblem
    {
        $answer = $this->providers->identify($provider, new VideoRequest(
            kind: $request->kind,
            title: $request->title,
            year: $request->year,
            externalIds: [$provider->value => $id],
            ordering: 'aired',
            libraryId: $request->libraryId,
            sidecars: $request->sidecars,
            titleId: $request->titleId,
        ));
        if ($answer instanceof VideoProviderProblem) {
            return $answer;
        }

        return $answer !== null && $answer->entry->id === $id && $answer->hasOrdering('aired')
            ? $answer : VideoProviderProblem::Unavailable;
    }

    private static function needsAbsoluteNumbers(VideoTitle $metadata): bool
    {
        foreach ($metadata->seasons as $season) {
            if ($season->number === 0) {
                continue;
            }
            foreach ($season->episodes as $episode) {
                if ($episode->absoluteNumber === null) {
                    return true;
                }
            }
        }

        return false;
    }
}
